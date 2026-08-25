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


def fExtraiTextoTextract(configuracao, job_id):
    cliente = boto3.client(
        "textract",
        region_name=configuracao["regiao_textract"],
    )

    paginas = {}
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
            if bloco["BlockType"] != "LINE":
                continue

            caixa = bloco["Geometry"]["BoundingBox"]

            paginas.setdefault(bloco["Page"], []).append(
                (
                    caixa["Top"],
                    caixa["Left"],
                    bloco["Text"].strip(),
                )
            )

        proximo_token = resposta.get("NextToken")

        if not proximo_token:
            break

    textos_paginas = []

    for numero_pagina in sorted(paginas):
        linhas = sorted(paginas[numero_pagina])
        textos_paginas.append("\n".join(linha[2] for linha in linhas))

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
    json_bruto = dados_resposta.get("response")

    if not isinstance(json_bruto, str):
        raise RuntimeError("Resposta inesperada do Ollama")

    return json_bruto


inicio_total = perf_counter()
configuracao = fCarregaConfiguracaoAmbiente()
cod_empresa = input("cod_empresa: ").strip()
cod_extracao = int(input("cod_extracao: ").strip())

query = """SELECT fila.cod_empresa,
                  fila.cod_extracao,
                  fila.cod_job_extracao,
                  fila.cod_usr_cadastro,
                  fila.dat_cadastro,
                  imagem.json_dados AS json_imagem
             FROM prt_fila_arquivo_extracao_dados AS fila
        LEFT JOIN prt_fila_arquivo_extracao_dados_json AS imagem ON imagem.cod_empresa = fila.cod_empresa
                                                                AND imagem.cod_extracao = fila.cod_extracao
                                                                AND imagem.tip_origem = 'B'
            WHERE fila.cod_empresa = %(cod_empresa)s
              AND fila.cod_extracao = %(cod_extracao)s
            LIMIT 1"""

parametros = {
    "cod_empresa": cod_empresa,
    "cod_extracao": cod_extracao,
}

with mysql.connector.connect(**configuracao["banco"]) as conexao:
    cursor = conexao.cursor(dictionary=True)
    cursor.execute(query, parametros)
    registro = cursor.fetchone()

imagem_base64 = fValidaENormalizaImagemBase64(registro["json_imagem"])
print("Imagem do banco carregada")

job_id = str(registro["cod_job_extracao"] or "").strip()
if not job_id:
    raise RuntimeError("Registro sem Job ID do Textract")

inicio = perf_counter()
situacao_textract, texto_documento = fExtraiTextoTextract(configuracao, job_id)

print(f"Textract: {situacao_textract} " f"({perf_counter() - inicio:.2f}s)")

if situacao_textract != "SUCCEEDED":
    print(f"Tempo total: {perf_counter() - inicio_total:.2f}s")
    raise SystemExit(1)

inicio = perf_counter()

resultado = json.loads(fGeraRespostaIa(configuracao, texto_documento, imagem_base64))

print(f"Ollama: JSON válido ({perf_counter() - inicio:.2f}s)")
print("\nResultado:")
print(json.dumps(resultado, ensure_ascii=False, indent=2))
print(f"\nTempo total: {perf_counter() - inicio_total:.2f}s")

if input("\nSalvar resultado no banco? [s/N]: ").strip().lower() != "s":
    print("Gravação cancelada. Nenhum registro foi alterado.")
    raise SystemExit(0)

query = """INSERT INTO prt_fila_arquivo_extracao_dados_json (
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

parametros = {
    "cod_empresa": registro["cod_empresa"],
    "cod_extracao": registro["cod_extracao"],
    "json_dados": json.dumps(resultado, ensure_ascii=False),
    "cod_usr_cadastro": registro["cod_usr_cadastro"],
    "dat_cadastro": registro["dat_cadastro"],
}

with mysql.connector.connect(**configuracao["banco"]) as conexao:
    cursor = conexao.cursor()
    cursor.execute(query, parametros)
    conexao.commit()

print(f"Registro salvo: {registro['cod_empresa']}/" f"{registro['cod_extracao']}/S")
