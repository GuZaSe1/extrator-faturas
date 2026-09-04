import base64
import json
import os
from pathlib import Path
from time import perf_counter

import boto3
import mysql.connector
from dotenv import load_dotenv
from openai import OpenAI

RAIZ_PROJETO = Path(__file__).resolve().parent.parent
CAMINHO_ENV = RAIZ_PROJETO / "python" / ".env"

DEBUG = True


def fCarregaConfiguracaoAmbiente():
    load_dotenv(CAMINHO_ENV)

    return {
        "banco": {
            "host": os.environ["DB_HOST"],
            "port": int(os.environ["DB_PORT"]),
            "database": os.environ["DB_NAME"],
            "user": os.environ["DB_USER"],
            "password": os.environ["DB_PASSWORD"],
            "charset": "utf8mb4",
            "autocommit": False,
            "connection_timeout": 10,
        },
        "regiao_textract": os.environ["AWS_DEFAULT_REGION"],
        "prompt_id": os.environ["OPENAI_PROMPT_ID"],
        "prompt_version": os.getenv("OPENAI_PROMPT_VERSION"),
    }


def fExtraiTextoTextract(cliente_textract, job_id):
    blocos_linha = []
    next_token = None

    while True:
        parametros = {
            "JobId": job_id,
            "MaxResults": 1000,
        }

        if next_token:
            parametros["NextToken"] = next_token

        resposta = cliente_textract.get_document_text_detection(**parametros)

        status = resposta.get("JobStatus")

        if status != "SUCCEEDED":
            raise RuntimeError(f"Textract retornou status: {status}")

        for bloco in resposta.get("Blocks", []):
            if bloco.get("BlockType") == "LINE":
                blocos_linha.append(bloco)

        next_token = resposta.get("NextToken")

        if not next_token:
            break

    blocos_linha.sort(
        key=lambda bloco: (
            bloco.get("Page", 1),
            bloco["Geometry"]["BoundingBox"]["Top"],
            bloco["Geometry"]["BoundingBox"]["Left"],
        )
    )

    paginas = {}

    for bloco in blocos_linha:
        pagina = bloco.get("Page", 1)
        texto = bloco.get("Text", "").strip()

        if texto:
            paginas.setdefault(
                pagina,
                [],
            ).append(texto)

    partes = []

    for pagina in sorted(paginas):
        partes.append(f"--- PÁGINA {pagina} ---")
        partes.extend(paginas[pagina])
        partes.append("")

    texto_documento = "\n".join(partes).strip()

    if not texto_documento:
        raise RuntimeError("Textract não retornou texto.")

    return texto_documento


def fValidaENormalizaImagemBase64(json_dados):
    imagem_base64 = json.loads(json_dados)

    if not isinstance(imagem_base64, str):
        raise RuntimeError("json_dados do registro B " "deve conter uma string Base64")

    imagem_base64 = imagem_base64.strip()

    imagem_base64 += "=" * (-len(imagem_base64) % 4)

    conteudo = base64.b64decode(imagem_base64, validate=True)

    if not conteudo.startswith(b"\xff\xd8\xff"):
        raise RuntimeError("json_dados do registro B " "não contém uma imagem JPEG")

    return imagem_base64


def fExtraiDadosOpenAI(
    cliente_openai, prompt_id, prompt_version, texto_documento, imagem_base64
):
    inicio = perf_counter()

    resposta = cliente_openai.responses.create(
        prompt={
            "id": prompt_id,
            "version": prompt_version,
        },
        input=[
            {
                "role": "user",
                "content": [
                    {
                        "type": "input_text",
                        "text": (
                            "TEXTO DA FATURA EXTRAÍDO PELO AWS TEXTRACT:\n\n"
                            + texto_documento
                        ),
                    },
                    {
                        "type": "input_image",
                        "image_url": ("data:image/jpeg;base64," + imagem_base64),
                    },
                ],
            }
        ],
        store=False,
    )

    tempo = perf_counter() - inicio

    if not resposta.output_text:
        raise RuntimeError("A OpenAI retornou resposta vazia.")

    try:
        resultado = json.loads(resposta.output_text)

    except json.JSONDecodeError as erro:
        raise RuntimeError(f"JSON inválido retornado pela OpenAI: {erro}")

    if DEBUG:
        print("\nOPENAI")

        if resposta.usage:
            input_tokens = resposta.usage.input_tokens or 0
            output_tokens = resposta.usage.output_tokens or 0

            cached_tokens = 0
            reasoning_tokens = 0

            if resposta.usage.input_tokens_details:
                cached_tokens = resposta.usage.input_tokens_details.cached_tokens or 0

            if resposta.usage.output_tokens_details:
                reasoning_tokens = (
                    resposta.usage.output_tokens_details.reasoning_tokens or 0
                )

            print(f"Input tokens: {input_tokens}")
            print(f"Cached tokens: {cached_tokens}")
            print(f"Output tokens: {output_tokens}")
            print(f"Reasoning tokens: {reasoning_tokens}")
            print(f"Total tokens: " f"{input_tokens + output_tokens}")

        print(f"Tempo OpenAI: {tempo:.2f}s")

    return resultado


def main():
    configuracao = fCarregaConfiguracaoAmbiente()

    cliente_openai = OpenAI(api_key=os.environ["OPENAI_API_KEY"], timeout=300.0)

    cliente_textract = boto3.client(
        "textract", region_name=configuracao["regiao_textract"]
    )

    conexao = mysql.connector.connect(**configuracao["banco"])

    processados = 0

    try:
        while True:

            cursor = conexao.cursor(dictionary=True)

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
            cursor.close()

            if registro is None:
                print("\nNão há mais registros pendentes.")
                break

            if registro is not None:

                # Busca a imagem somente depois de escolher a fatura
                cursor = conexao.cursor(dictionary=True)

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
                cursor.close()

                if imagem is None:
                    raise RuntimeError("Imagem Base64 não encontrada para o registro")

                imagem_base64 = fValidaENormalizaImagemBase64(imagem["json_imagem"])

            identificador = f"{registro['cod_empresa']}/" f"{registro['cod_extracao']}"

            print("\n================================")
            print(f"Processando: {identificador}")
            print("================================")

            inicio_total = perf_counter()

            try:
                job_id = str(registro["cod_job_extracao"] or "").strip()

                if not job_id:
                    raise RuntimeError("Registro sem Job ID " "do Textract.")

                texto_documento = fExtraiTextoTextract(cliente_textract, job_id)

                resultado = fExtraiDadosOpenAI(
                    cliente_openai,
                    configuracao["prompt_id"],
                    configuracao["prompt_version"],
                    texto_documento,
                    imagem_base64,
                )

                # if DEBUG:
                #     print("\nRESULTADO")
                #     print(json.dumps(resultado, indent=2, ensure_ascii=False))

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

                params_insert = {
                    "cod_empresa": registro["cod_empresa"],
                    "cod_extracao": registro["cod_extracao"],
                    "json_dados": json.dumps(resultado, ensure_ascii=False),
                    "cod_usr_cadastro": registro["cod_usr_cadastro"],
                    "dat_cadastro": registro["dat_cadastro"],
                }

                cursor = conexao.cursor()

                cursor.execute(query_insert, params_insert)

                conexao.commit()
                cursor.close()

                processados += 1

                tempo_total = perf_counter() - inicio_total

                print(
                    f"\nFinalizado: " f"{identificador}",
                    f"Tempo total: " f"{tempo_total:.2f}s",
                )

            except Exception:
                conexao.rollback()
                raise

    except KeyboardInterrupt:
        print("\nProcessamento interrompido.")

    except Exception as erro:
        print("\nERRO:")

        print(f"{type(erro).__name__}: " f"{erro}")

    finally:
        if conexao.is_connected():
            conexao.close()

    print(f"\nTotal processado: " f"{processados}")


if __name__ == "__main__":
    main()
