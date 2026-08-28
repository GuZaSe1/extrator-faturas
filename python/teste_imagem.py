import base64
import json
import os
from pathlib import Path
from time import perf_counter

import boto3
import mysql.connector
import requests
from dotenv import load_dotenv

RAIZ_PROJETO = Path(__file__).resolve().parent.parent

CAMINHO_ENV = RAIZ_PROJETO / "python" / ".env"
CAMINHO_PROMPT = RAIZ_PROJETO / "prompts" / "prompt_imagem.txt"


def fCarregaConfiguracaoAmbiente():
    load_dotenv(CAMINHO_ENV)

    return {
        "banco": {
            "host": os.environ["DB_HOST"],
            "port": os.environ["DB_PORT"],
            "database": os.environ["DB_NAME"],
            "user": os.environ["DB_USER"],
            "password": os.environ["DB_PASSWORD"],
            "charset": "utf8mb4",
            "autocommit": False,
            "connection_timeout": 10,
        },
        "regiao_textract": os.environ["AWS_DEFAULT_REGION"],
        "ollama_url": os.environ["OLLAMA_URL"],
        "ollama_modelo": os.environ["OLLAMA_MODEL"],
    }


def fMontaLinhaEspacial(bloco_linha, blocos_por_id):
    palavras = []

    for relacionamento in bloco_linha.get("Relationships", []):
        if relacionamento.get("Type") != "CHILD":
            continue

        for bloco_id in relacionamento.get("Ids", []):
            bloco = blocos_por_id.get(bloco_id)

            if not bloco or bloco.get("BlockType") != "WORD":
                continue

            caixa = bloco["Geometry"]["BoundingBox"]

            palavras.append((caixa["Left"], bloco.get("Text", "").strip()))

    if not palavras:
        return bloco_linha.get("Text", "").strip()

    palavras.sort(key=lambda item: item[0])

    linha = []
    posicao_atual = 0

    for left, texto in palavras:
        if not texto:
            continue

        posicao_desejada = round(left * 140)
        posicao_desejada = max(posicao_desejada, posicao_atual + (1 if linha else 0))

        quantidade_espacos = max(0, posicao_desejada - posicao_atual)

        if quantidade_espacos:
            linha.append(" " * quantidade_espacos)

        linha.append(texto)
        posicao_atual = posicao_desejada + len(texto)

    return "".join(linha).rstrip()


def fExtraiTextoTextract(configuracao, job_id):
    cliente = boto3.client("textract", region_name=configuracao["regiao_textract"])

    blocos_por_id = {}
    linhas_por_pagina = {}
    proximo_token = None

    while True:
        parametros = {
            "JobId": job_id,
            "MaxResults": 1000,
        }

        if proximo_token:
            parametros["NextToken"] = proximo_token

        resposta = cliente.get_document_text_detection(**parametros)
        situacao = resposta["JobStatus"]

        if situacao != "SUCCEEDED":
            return situacao, None

        for bloco in resposta.get("Blocks", []):
            bloco_id = bloco.get("Id")

            if bloco_id:
                blocos_por_id[bloco_id] = bloco

            if bloco.get("BlockType") == "LINE":
                numero_pagina = bloco.get("Page", 1)
                linhas_por_pagina.setdefault(numero_pagina, []).append(bloco)

        proximo_token = resposta.get("NextToken")

        if not proximo_token:
            break

    textos_paginas = []

    for numero_pagina in sorted(linhas_por_pagina):
        linhas = sorted(
            linhas_por_pagina[numero_pagina],
            key=lambda bloco: (
                bloco["Geometry"]["BoundingBox"]["Top"],
                bloco["Geometry"]["BoundingBox"]["Left"],
            ),
        )

        texto_pagina = [f"--- PÁGINA {numero_pagina} ---"]

        for linha in linhas:
            texto_linha = fMontaLinhaEspacial(linha, blocos_por_id)

            if texto_linha:
                texto_pagina.append(texto_linha)

        textos_paginas.append("\n".join(texto_pagina))

    texto_documento = "\n\n".join(textos_paginas).strip()

    return "SUCCEEDED", texto_documento


def fValidaENormalizaImagemBase64(json_dados):
    imagem_base64 = json.loads(json_dados)
    if not isinstance(imagem_base64, str):
        raise RuntimeError("json_dados do registro B deve conter uma string Base64")

    imagem_base64 = imagem_base64.strip()
    imagem_base64 += "=" * (-len(imagem_base64) % 4)
    conteudo = base64.b64decode(imagem_base64, validate=True)

    if not conteudo.startswith(b"\xff\xd8\xff"):
        raise RuntimeError("json_dados do registro B não contém uma imagem JPEG")

    return base64.b64encode(conteudo).decode("ascii")


def fGeraRespostaIa(configuracao, texto_documento, imagem_base64):
    texto_documento = texto_documento.strip()

    if not texto_documento:
        raise RuntimeError("O texto extraído pela AWS está vazio")

    prompt = CAMINHO_PROMPT.read_text(encoding="utf-8").replace(
        "{{TEXTO_PDF}}", texto_documento
    )

    payload = {
        "model": configuracao["ollama_modelo"],
        "prompt": prompt,
        "images": [imagem_base64],
        "stream": False,
        "format": "json",
        "think": False,
        "options": {
            "temperature": 0,
            "num_ctx": 12288,
            "num_predict": 4096,
        },
    }

    resposta = requests.post(
        configuracao["ollama_url"],
        json=payload,
        timeout=(10, 2400),
    )
    resposta.raise_for_status()

    dados_resposta = resposta.json()

    # eval_count = dados_resposta.get("eval_count", 0)
    # eval_duration = dados_resposta.get("eval_duration", 0) / 1e9

    # velocidade = eval_count / eval_duration if eval_duration > 0 else 0

    # print(
    #     "\nOLLAMA:",
    #     f"\nTotal: {dados_resposta.get('total_duration', 0) / 1e9:.2f}s",
    #     f"\nLoad: {dados_resposta.get('load_duration', 0) / 1e9:.2f}s",
    #     f"\nPrompt tokens: {dados_resposta.get('prompt_eval_count')}",
    #     f"\nPrompt: {dados_resposta.get('prompt_eval_duration', 0) / 1e9:.2f}s",
    #     f"\nOutput tokens: {eval_count}",
    #     f"\nGeração: {eval_duration:.2f}s",
    #     f"\nVelocidade: {velocidade:.2f} tokens/s",
    # )

    json_bruto = dados_resposta.get("response")

    if not isinstance(json_bruto, str):
        raise RuntimeError("Resposta inesperada do Ollama")

    return json_bruto


def main():
    configuracao = fCarregaConfiguracaoAmbiente()
    quantidade_processada = 0
    quantidade_erros = 0

    while True:
        with mysql.connector.connect(**configuracao["banco"]) as conexao:
            with conexao.cursor(dictionary=True) as cursor:

                query = """SELECT fila.cod_empresa,
                                  fila.cod_extracao,
                                  fila.cod_job_extracao,
                                  fila.cod_usr_cadastro,
                                  fila.dat_cadastro
                             FROM prt_fila_arquivo_extracao_dados AS fila
                       INNER JOIN prt_fila_arquivo_extracao_dados_json AS origem ON origem.cod_empresa = fila.cod_empresa
                                                                                AND origem.cod_extracao = fila.cod_extracao
                                                                                AND origem.tip_origem = 'B'
                        LEFT JOIN prt_fila_arquivo_extracao_dados_json AS processada ON processada.cod_empresa = fila.cod_empresa
                                                                                    AND processada.cod_extracao = fila.cod_extracao
                                                                                    AND processada.tip_origem = 'S'
                            WHERE fila.dat_cadastro >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                              AND processada.cod_empresa IS NULL
                         ORDER BY fila.dat_cadastro ASC,
                                  fila.cod_empresa ASC,
                                  fila.cod_extracao ASC
                            LIMIT 1"""

                cursor.execute(query)
                registro = cursor.fetchone()

                if registro is not None:

                    query_base64 = """SELECT json_dados AS json_imagem
                                        FROM prt_fila_arquivo_extracao_dados_json
                                       WHERE cod_empresa = %(cod_empresa)s
                                         AND cod_extracao = %(cod_extracao)s
                                         AND tip_origem = 'B'"""

                    parametros_base64 = {
                        "cod_empresa": registro["cod_empresa"],
                        "cod_extracao": registro["cod_extracao"],
                    }

                    cursor.execute(query_base64, parametros_base64)
                    imagem = cursor.fetchone()

                    if imagem is None:
                        raise RuntimeError("Imagem B não encontrada para o registro")

                    registro["json_imagem"] = imagem["json_imagem"]

        if registro is None:
            print(
                f"Fila concluída: {quantidade_processada} registro(s), "
                f"{quantidade_erros} erro(s)."
            )
            break

        identificador = f"{registro['cod_empresa']}/{registro['cod_extracao']}"
        print(f"Processando: {identificador}", flush=True)

        inicio_processamento = perf_counter()
        processamento_com_erro = False

        try:
            imagem_base64 = fValidaENormalizaImagemBase64(registro["json_imagem"])

            job_id = str(registro["cod_job_extracao"] or "").strip()

            if not job_id:
                raise RuntimeError("Registro sem Job ID do Textract")

            situacao_textract, texto_documento = fExtraiTextoTextract(
                configuracao, job_id
            )

            if situacao_textract != "SUCCEEDED":
                raise RuntimeError(f"Textract retornou o status {situacao_textract}")

            resultado = json.loads(
                fGeraRespostaIa(configuracao, texto_documento, imagem_base64)
            )

            if not isinstance(resultado, dict):
                raise RuntimeError("Resposta do Ollama deve conter um objeto JSON")

        except Exception as erro:
            processamento_com_erro = True
            resultado = {
                "erro": {
                    "tipo": type(erro).__name__,
                    "mensagem": str(erro),
                }
            }

        resultado["tempo_processamento_segundos"] = round(
            perf_counter() - inicio_processamento, 2
        )

        with mysql.connector.connect(**configuracao["banco"]) as conexao:
            with conexao.cursor() as cursor:
                try:

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
                        "json_dados": json.dumps(resultado, ensure_ascii=False),
                        "cod_usr_cadastro": registro["cod_usr_cadastro"],
                        "dat_cadastro": registro["dat_cadastro"],
                    }

                    cursor.execute(query_insert, parametros_insert)

                    if not processamento_com_erro:

                        query_delete = """DELETE FROM prt_fila_arquivo_extracao_dados_json
                                                WHERE cod_empresa = %(cod_empresa)s
                                                  AND cod_extracao = %(cod_extracao)s
                                                  AND tip_origem = 'B'"""

                        parametros_delete = {
                            "cod_empresa": registro["cod_empresa"],
                            "cod_extracao": registro["cod_extracao"],
                        }

                        cursor.execute(query_delete, parametros_delete)

                    conexao.commit()

                except Exception:
                    if conexao.is_connected():
                        conexao.rollback()
                    raise

        quantidade_processada += 1
        tempo = resultado["tempo_processamento_segundos"]

        if processamento_com_erro:
            quantidade_erros += 1
            print(
                f"Erro salvo: {identificador} - "
                f"{resultado['erro']['tipo']}: {resultado['erro']['mensagem']} "
                f"({tempo:.2f}s)"
            )
        else:
            print(f"Finalizado: {identificador} ({tempo:.2f}s)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
