import copy
import importlib.util
import io
import json
import sys
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch


CAMINHO_MODULO = Path(__file__).resolve().parents[1] / "python" / "agentes_nf3e.py"
ESPECIFICACAO = importlib.util.spec_from_file_location("agentes_nf3e", CAMINHO_MODULO)
agentes_nf3e = importlib.util.module_from_spec(ESPECIFICACAO)
sys.modules[ESPECIFICACAO.name] = agentes_nf3e
ESPECIFICACAO.loader.exec_module(agentes_nf3e)


def fDadosFaturaSemProdutosVazios():
    return agentes_nf3e.DadosFaturaSemProdutos(
        chave_acesso=None,
        num_cnpj_emit=None,
        num_cnpj_dest=None,
        num_nf=None,
        referencia=None,
        cod_unidade_consumo=None,
        dat_emissao=None,
        cod_subgrupo=None,
        codigo_modalidade=None,
        val_total=None,
        dat_leitura_anterior=None,
        dat_leitura_atual=None,
        dta_leitura_prox=None,
        dat_vencimento=None,
        demanda_contratada_fp=None,
        demanda_contratada_p=None,
        pct_cofins=None,
        pct_pis=None,
        historico=[],
    )


def fConfiguracao():
    return agentes_nf3e.Configuracao(
        ollama_url_geracao="http://ollama/api/generate",
        modelo_extrator="qwen2.5:7b",
        modelo_produtos="qwen2.5:7b",
        modelo_validador="qwen2.5:7b",
    )


class TesteConfiguracao(unittest.TestCase):
    def test_modelo_produtos_reutiliza_modelo_extrator_por_padrao(self):
        with TemporaryDirectory() as diretorio:
            caminho = Path(diretorio) / ".env"
            caminho.write_text(
                "OLLAMA_URL=http://ollama\n"
                "OLLAMA_EXTRACTOR_MODEL=modelo-especializado\n",
                encoding="utf-8",
            )
            with patch.dict(agentes_nf3e.os.environ, {}, clear=True):
                configuracao = agentes_nf3e.fCarregaConfiguracao(caminho)

        self.assertEqual("modelo-especializado", configuracao.modelo_produtos)


class RespostaHttpFalsa:
    def __init__(self, corpo):
        self.corpo = corpo

    def raise_for_status(self):
        return None

    def json(self):
        return self.corpo


class SessaoHttpFalsa:
    def __init__(self, corpo):
        self.corpo = corpo
        self.chamadas = []

    def post(self, url, json, timeout):
        self.chamadas.append({"url": url, "json": json, "timeout": timeout})
        return RespostaHttpFalsa(self.corpo)


class AgenteFalso:
    def __init__(self, resultado, metricas=None):
        self.resultado = resultado
        self.entradas = []
        self.metricas_ultima_execucao = metricas or {}

    def executar(self, entrada):
        self.entradas.append(entrada)
        return self.resultado


class TesteLeituraTextoAws(unittest.TestCase):
    def test_junta_linhas_e_separa_paginas(self):
        conteudo = {
            "texto_por_pagina": [
                {"linhas": [" Linha A ", "Linha B"]},
                {"linhas": ["Linha C"]},
            ]
        }

        with TemporaryDirectory() as diretorio:
            caminho = Path(diretorio) / "aws.json"
            caminho.write_text(json.dumps(conteudo), encoding="utf-8")
            texto = agentes_nf3e.fLeTextoAws(caminho)

        self.assertEqual("Linha A\nLinha B\n\nLinha C", texto)

    def test_le_arquivo_txt_diretamente(self):
        with TemporaryDirectory() as diretorio:
            caminho = Path(diretorio) / "aws.txt"
            caminho.write_text(" Linha A\nLinha B ", encoding="utf-8")

            texto = agentes_nf3e.fLeTextoAws(caminho)

        self.assertEqual("Linha A\nLinha B", texto)

    def test_rejeita_json_sem_texto_por_pagina(self):
        with TemporaryDirectory() as diretorio:
            caminho = Path(diretorio) / "aws.json"
            caminho.write_text("{}", encoding="utf-8")

            with self.assertRaisesRegex(RuntimeError, "texto_por_pagina"):
                agentes_nf3e.fLeTextoAws(caminho)


class TesteAgenteOllama(unittest.TestCase):
    def test_envia_schema_ao_ollama_e_valida_resposta(self):
        corpo = {
            "model": "qwen2.5:7b",
            "done_reason": "stop",
            "total_duration": 2_000_000_000,
            "load_duration": 250_000_000,
            "prompt_eval_count": 100,
            "prompt_eval_duration": 500_000_000,
            "eval_count": 50,
            "eval_duration": 1_000_000_000,
            "response": json.dumps(
                {"aprovado": True, "erros": [], "observacoes": []},
                ensure_ascii=False,
            )
        }
        sessao = SessaoHttpFalsa(corpo)
        agente = agentes_nf3e.AgenteOllama(
            nome="Validador",
            modelo="qwen2.5:7b",
            instrucoes="Valide",
            tipo_saida=agentes_nf3e.ResultadoValidacao,
            url_geracao="http://ollama/api/generate",
            num_ctx=4096,
            num_predict=512,
            sessao_http=sessao,
        )

        resultado = agente.executar("entrada")

        self.assertTrue(resultado.aprovado)
        payload = sessao.chamadas[0]["json"]
        self.assertEqual("qwen2.5:7b", payload["model"])
        self.assertIn("properties", payload["format"])
        self.assertIn("aprovado", payload["format"]["properties"])
        self.assertEqual(0, payload["options"]["temperature"])
        self.assertEqual(
            {
                "total": 2.0,
                "carregamento": 0.25,
                "avaliacao_prompt": 0.5,
                "geracao": 1.0,
            },
            agente.metricas_ultima_execucao["duracoes_segundos"],
        )
        self.assertEqual(
            {"prompt": 100, "resposta": 50},
            agente.metricas_ultima_execucao["tokens"],
        )
        self.assertEqual(
            50.0, agente.metricas_ultima_execucao["tokens_resposta_por_segundo"]
        )

    def test_rejeita_resposta_fora_do_schema(self):
        sessao = SessaoHttpFalsa({"response": "{}"})
        agente = agentes_nf3e.AgenteOllama(
            nome="Validador",
            modelo="qwen2.5:7b",
            instrucoes="Valide",
            tipo_saida=agentes_nf3e.ResultadoValidacao,
            url_geracao="http://ollama/api/generate",
            num_ctx=4096,
            num_predict=512,
            sessao_http=sessao,
        )

        with self.assertRaisesRegex(RuntimeError, "schema esperado"):
            agente.executar("entrada")

    def test_validacao_rejeitada_exige_erro(self):
        with self.assertRaisesRegex(ValueError, "ao menos um erro"):
            agentes_nf3e.ResultadoValidacao(
                aprovado=False,
                erros=[],
                observacoes=[],
            )


class TesteCriacaoAgentes(unittest.TestCase):
    def test_agente_produtos_tem_prompt_e_schema_exclusivos(self):
        extrator, produtos, validador = agentes_nf3e.fCriaAgentes(fConfiguracao())

        self.assertIs(extrator.tipo_saida, agentes_nf3e.DadosFaturaSemProdutos)
        self.assertNotIn(
            "produtos", extrator.tipo_saida.model_json_schema()["properties"]
        )
        self.assertIs(produtos.tipo_saida, agentes_nf3e.ProdutosFatura)
        self.assertEqual(
            {"produtos"}, set(produtos.tipo_saida.model_json_schema()["properties"])
        )
        self.assertIn("exclusivamente", produtos.instrucoes)
        self.assertIs(validador.tipo_saida, agentes_nf3e.ResultadoValidacao)


class TesteOrquestracao(unittest.TestCase):
    def test_rejeicao_preserva_dados_extraidos(self):
        dados = fDadosFaturaSemProdutosVazios()
        dados.num_nf = "123"
        produtos_extraidos = agentes_nf3e.ProdutosFatura(
            produtos=[
                agentes_nf3e.Produto(
                    descricao="ENERGIA ATIVA",
                    unidade="kWh",
                    quantidade="10,000",
                    preco="0,50000",
                    valor="5,00",
                )
            ]
        )
        dados_antes = copy.deepcopy(dados.model_dump())
        dados_antes["produtos"] = produtos_extraidos.model_dump()["produtos"]
        validacao = agentes_nf3e.ResultadoValidacao(
            aprovado=False,
            erros=[
                agentes_nf3e.ErroValidacao(
                    campo="num_nf",
                    valor_extraido="123",
                    mensagem="Número não sustentado pelo texto",
                    evidencia_aws=None,
                )
            ],
            observacoes=[],
        )
        extrator = AgenteFalso(dados)
        produtos = AgenteFalso(produtos_extraidos)
        validador = AgenteFalso(validacao)

        resultado = agentes_nf3e.fExecutaFluxo(
            fConfiguracao(),
            "FATURA ORIGINAL",
            origem_texto="/tmp/aws.json",
            cod_empresa="EMPRESA",
            cod_extracao=10,
            agentes=(extrator, produtos, validador),
        )

        self.assertFalse(resultado["validacao"]["aprovado"])
        self.assertEqual(dados_antes, resultado["dados_extraidos"])
        self.assertIn("FATURA ORIGINAL", extrator.entradas[0])
        self.assertIn("FATURA ORIGINAL", produtos.entradas[0])
        self.assertIn("FATURA ORIGINAL", validador.entradas[0])
        self.assertIn("\"num_nf\": \"123\"", validador.entradas[0])
        self.assertIn("\"descricao\": \"ENERGIA ATIVA\"", validador.entradas[0])
        self.assertEqual("/tmp/aws.json", resultado["metadados"]["origem_texto"])

    def test_aprovacao_gera_envelope_completo(self):
        extrator = AgenteFalso(
            fDadosFaturaSemProdutosVazios(),
            metricas={"tokens": {"prompt": 1000, "resposta": 200}},
        )
        produtos = AgenteFalso(
            agentes_nf3e.ProdutosFatura(produtos=[]),
            metricas={"tokens": {"prompt": 600, "resposta": 50}},
        )
        validador = AgenteFalso(
            agentes_nf3e.ResultadoValidacao(
                aprovado=True,
                erros=[],
                observacoes=["Campos ausentes também não aparecem no texto"],
            )
        )

        resultado = agentes_nf3e.fExecutaFluxo(
            fConfiguracao(),
            "FATURA ORIGINAL",
            origem_texto="texto_aws.json",
            agentes=(extrator, produtos, validador),
        )

        self.assertEqual(
            {"metadados", "dados_extraidos", "validacao"}, set(resultado.keys())
        )
        self.assertTrue(resultado["validacao"]["aprovado"])
        self.assertEqual("qwen2.5:7b", resultado["metadados"]["modelos"]["extrator"])
        self.assertEqual("qwen2.5:7b", resultado["metadados"]["modelos"]["produtos"])
        self.assertNotIn("banco", resultado["metadados"]["tempos_segundos"])
        self.assertNotIn("textract", resultado["metadados"]["tempos_segundos"])
        self.assertIn("produtos", resultado["metadados"]["tempos_segundos"])
        self.assertEqual(
            1000,
            resultado["metadados"]["metricas_ollama"]["extrator"]["tokens"][
                "prompt"
            ],
        )
        self.assertEqual(
            600,
            resultado["metadados"]["metricas_ollama"]["produtos"]["tokens"][
                "prompt"
            ],
        )


class TesteLogValidacao(unittest.TestCase):
    def test_salva_campos_errados_sem_texto_completo_da_fatura(self):
        resultado = {
            "metadados": {
                "cod_empresa": "EMPRESA",
                "cod_extracao": 10,
                "origem_texto": "texto_aws.json",
                "modelos": {
                    "extrator": "qwen2.5:7b",
                    # "extrator": "qwen2.5:14b",
                    "validador": "qwen2.5:7b",
                },
                "metricas_ollama": {
                    "extrator": {"tokens": {"prompt": 1000, "resposta": 200}}
                },
            },
            "dados_extraidos": {"texto_completo": "CONTEUDO_NAO_DEVE_IR_AO_LOG"},
            "validacao": {
                "aprovado": False,
                "erros": [
                    {
                        "campo": "val_total",
                        "valor_extraido": "100,00",
                        "mensagem": "Valor diverge da fatura",
                        "evidencia_aws": "TOTAL 120,00",
                    },
                    {
                        "campo": "produtos[0].valor",
                        "valor_extraido": "50,00",
                        "mensagem": "Produto diverge da fatura",
                        "evidencia_aws": "ENERGIA 60,00",
                    },
                ],
                "observacoes": [],
            },
        }

        with TemporaryDirectory() as diretorio:
            caminho = Path(diretorio) / "validacao.log"
            retorno = agentes_nf3e.fRegistraCamposInvalidos(resultado, caminho)
            conteudo = caminho.read_text(encoding="utf-8")

        registro = json.loads(conteudo)
        self.assertEqual(caminho, retorno)
        self.assertEqual(
            ["val_total", "produtos[0].valor"], registro["campos_errados"]
        )
        self.assertEqual("EMPRESA", registro["cod_empresa"])
        self.assertEqual("texto_aws.json", registro["origem_texto"])
        self.assertEqual(
            1000,
            registro["metricas_ollama"]["extrator"]["tokens"]["prompt"],
        )
        self.assertNotIn("CONTEUDO_NAO_DEVE_IR_AO_LOG", conteudo)
        self.assertNotIn("dados_extraidos", registro)

    def test_nao_cria_log_quando_validacao_foi_aprovada(self):
        resultado = {
            "validacao": {"aprovado": True, "erros": [], "observacoes": []}
        }

        with TemporaryDirectory() as diretorio:
            caminho = Path(diretorio) / "validacao.log"
            retorno = agentes_nf3e.fRegistraCamposInvalidos(resultado, caminho)
            existe = caminho.exists()

        self.assertIsNone(retorno)
        self.assertFalse(existe)


class TesteCodigosSaida(unittest.TestCase):
    def test_main_retorna_zero_quando_aprovado(self):
        resultado = {"validacao": {"aprovado": True}}
        with patch.object(agentes_nf3e, "fCarregaConfiguracao", return_value=fConfiguracao()), patch.object(
            agentes_nf3e, "fExecutaFluxo", return_value=resultado
        ), redirect_stdout(io.StringIO()):
            codigo = agentes_nf3e.main(
                ["--cod-empresa", "EMPRESA", "--cod-extracao", "10"]
            )
        self.assertEqual(0, codigo)

    def test_main_retorna_dois_quando_rejeitado(self):
        resultado = {"validacao": {"aprovado": False}}
        with patch.object(agentes_nf3e, "fCarregaConfiguracao", return_value=fConfiguracao()), patch.object(
            agentes_nf3e, "fExecutaFluxo", return_value=resultado
        ), patch.object(
            agentes_nf3e, "fRegistraCamposInvalidos", return_value=Path("/tmp/teste.log")
        ), redirect_stdout(io.StringIO()):
            codigo = agentes_nf3e.main(
                ["--cod-empresa", "EMPRESA", "--cod-extracao", "10"]
            )
        self.assertEqual(2, codigo)

    def test_main_retorna_um_e_escreve_erro_no_stderr(self):
        saida_erro = io.StringIO()
        with patch.object(
            agentes_nf3e,
            "fCarregaConfiguracao",
            side_effect=RuntimeError("configuração inválida"),
        ), redirect_stderr(saida_erro):
            codigo = agentes_nf3e.main(
                ["--cod-empresa", "EMPRESA", "--cod-extracao", "10"]
            )

        self.assertEqual(1, codigo)
        self.assertIn("configuração inválida", saida_erro.getvalue())


if __name__ == "__main__":
    unittest.main()
