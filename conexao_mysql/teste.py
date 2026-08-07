import json
import os
from pathlib import Path
from time import perf_counter # biblioteca p/ contar o tempo

import boto3
import mysql.connector
import requests
from dotenv import load_dotenv

DIRETORIO_SCRIPT = Path(__file__).resolve().parent
CAMINHO_ENV = DIRETORIO_SCRIPT / ".env"
CAMINHO_PROMPT = DIRETORIO_SCRIPT.parent / "prompts" / "fatura_nf3e.txt"


def fCarregaConfiguracao():
    load_dotenv(CAMINHO_ENV)

    configuracao = {
        "banco": {
            "host": os.environ["DB_HOST"],
            "port": os.environ["DB_PORT"],
            "database": os.environ["DB_NAME"],
            "user": os.environ["DB_USER"],
            "password": os.environ["DB_PASSWORD"],
            "charset": "utf8mb4",
            "autocommit": False,
            "connection_timeout": 10
        },
        "regiao_textract": os.environ["AWS_DEFAULT_REGION"],
        "ollama_url": os.environ["OLLAMA_URL"],
        "ollama_modelo": os.environ["OLLAMA_MODEL"]
    }

    return configuracao


def fExtraiTextoTextract(configuracao, job_id):
    cliente_textract = boto3.client("textract", region_name=configuracao["regiao_textract"])

    parametros = {
        "JobId": job_id,
        "MaxResults": 1000
    }

    paginas = {}

    while True:
        resposta = cliente_textract.get_document_text_detection(**parametros)
        situacao = resposta.get("JobStatus")

        if situacao != "SUCCEEDED":
            return {
                "situacao": situacao,
                "texto": None
            }

        # Agrupa as linhas pela página e pela posição no documento
        for bloco in resposta.get("Blocks", []):
            if bloco.get("BlockType") != "LINE":
                continue

            texto_linha = str(bloco.get("Text", "")).strip()
            if not texto_linha:
                continue

            numero_pagina = int(bloco.get("Page") or 1)
            caixa = bloco.get("Geometry", {}).get("BoundingBox", {})
            posicao_linha = (
                float(caixa.get("Top", 0)),
                float(caixa.get("Left", 0)),
                texto_linha
            )

            if numero_pagina not in paginas:
                paginas[numero_pagina] = []

            paginas[numero_pagina].append(posicao_linha)

        proximo_token = resposta.get("NextToken")
        if not proximo_token:
            break

        parametros["NextToken"] = proximo_token

    textos_paginas = []

    for numero_pagina in sorted(paginas):
        linhas_ordenadas = sorted(paginas[numero_pagina])
        textos_linhas = []

        for linha in linhas_ordenadas:
            textos_linhas.append(linha[2])

        textos_paginas.append("\n".join(textos_linhas))

    texto_documento = "\n\n".join(textos_paginas).strip()

    if not texto_documento:
        raise RuntimeError("Textract não retornou texto")

    return {
        "situacao": "SUCCEEDED",
        "texto": texto_documento
    }


def fGeraRespostaIa(configuracao, texto_documento):
    template = CAMINHO_PROMPT.read_text(encoding="utf-8")

    # O template define as regras; o texto extraído entra no marcador
    prompt_completo = template.replace("{{TEXTO_PDF}}", texto_documento)

    payload = {
        "model": configuracao["ollama_modelo"],
        "prompt": prompt_completo,
        "stream": False,
        "format": "json",
        "options": {
            "temperature": 0,
            "num_ctx": 12288,
            "num_predict": 4096
        }
    }

    resposta = requests.post(
        configuracao["ollama_url"],
        json=payload,
        timeout=(10, 2400)
    )
    resposta.raise_for_status()

    dados_resposta = resposta.json()
    json_bruto = dados_resposta.get("response")

    if not isinstance(json_bruto, str):
        raise RuntimeError("Resposta inesperada do Ollama")

    return json_bruto


def fExecutaTeste():
    try:
        inicio_total = perf_counter()

        # 1. Configuração e entrada
        configuracao = fCarregaConfiguracao()
        cod_empresa = input("cod_empresa: ").strip()
        cod_extracao = int(input("cod_extracao: ").strip())

        # 2. Consulta somente leitura no MySQL
        query = """SELECT cod_empresa,
                          cod_extracao,
                          cod_job_extracao,
                          cod_usr_cadastro,
                          dat_cadastro
                     FROM prt_fila_arquivo_extracao_dados
                    WHERE cod_empresa = %(cod_empresa)s
                      AND cod_extracao = %(cod_extracao)s
                    LIMIT 1"""

        parametros = {
            "cod_empresa": cod_empresa,
            "cod_extracao": cod_extracao
        }

        with mysql.connector.connect(**configuracao["banco"]) as conexao:
            cursor = conexao.cursor(dictionary=True)
            cursor.execute("START TRANSACTION READ ONLY")
            cursor.execute(query, parametros)
            registro = cursor.fetchone()

        if registro is None:
            raise RuntimeError("Registro não encontrado")

        job_id = registro["cod_job_extracao"]

        if job_id is None or not str(job_id).strip():
            raise RuntimeError("Registro sem Job ID do Textract")

        job_id = str(job_id).strip()

        # 3. Extração do texto pela AWS Textract
        inicio_textract = perf_counter()
        resultado_textract = fExtraiTextoTextract(configuracao, job_id)

        tempo_textract = perf_counter() - inicio_textract
        situacao_textract = resultado_textract["situacao"]

        print(f"Textract: {situacao_textract} ({tempo_textract:.2f}s)")

        if situacao_textract != "SUCCEEDED":
            print(f"Tempo total: {perf_counter() - inicio_total:.2f}s")
            return 1

        # 4. Processamento do texto pelo Ollama
        inicio_ollama = perf_counter()
        json_bruto = fGeraRespostaIa(configuracao, resultado_textract["texto"])
        tempo_ollama = perf_counter() - inicio_ollama

        resultado_decodificado = json.loads(json_bruto)

        # 5. Exibição do resultado
        print(f"Ollama: JSON válido ({tempo_ollama:.2f}s)")
        print("\nResultado:")
        print(
            json.dumps(
                resultado_decodificado,
                ensure_ascii=False,
                indent=2
            )
        )
        print(f"\nTempo total: {perf_counter() - inicio_total:.2f}s")

        # 6. Confirmação e gravação do resultado
        confirmacao = input("\nSalvar resultado no banco? [s/N]: ").strip().lower()

        if confirmacao != "s":
            print("Gravação cancelada. Nenhum registro foi alterado.")
            return 0

        query_insert = """INSERT INTO prt_fila_arquivo_extracao_dados_json (
                                      cod_empresa,
                                      cod_extracao,
                                      json_dados,
                                      tip_origem,
                                      cod_usr_cadastro,
                                      dat_cadastro
                             ) VALUES (
                                      %(cod_empresa)s,
                                      %(cod_extracao)s,
                                      %(json_dados)s,
                                      'S',
                                      %(cod_usr_cadastro)s,
                                      %(dat_cadastro)s)"""

        parametros_insert = {
            "cod_empresa": registro["cod_empresa"],
            "cod_extracao": registro["cod_extracao"],
            "json_dados": json.dumps(resultado_decodificado, ensure_ascii=False),
            "cod_usr_cadastro": registro["cod_usr_cadastro"],
            "dat_cadastro": registro["dat_cadastro"],
        }

        with mysql.connector.connect(**configuracao["banco"]) as conexao:
            cursor = conexao.cursor()
            cursor.execute(query_insert, parametros_insert)
            conexao.commit()

        print(
            "Registro salvo: "
            f"{registro['cod_empresa']}/{registro['cod_extracao']}/S"
        )
        return 0

    except KeyboardInterrupt:
        print("\nTeste interrompido")
        return 130
    except Exception as erro:
        print(f"\nErro: {erro}")
        return 1


if __name__ == "__main__":
    raise SystemExit(fExecutaTeste())
