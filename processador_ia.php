<?php

require_once __DIR__ . '/src/Debug/DebugLogger.php';

require_once __DIR__ . '/src/Ollama/OllamaClient.php';
require_once __DIR__ . '/src/Ollama/OllamaPromptBuilder.php';
require_once __DIR__ . '/src/Ollama/IaJsonResponseSanitizer.php';

require_once __DIR__ . '/src/FaturaNf3e/Nf3eInvoiceTextFilter.php';
require_once __DIR__ . '/src/FaturaNf3e/Nf3eDeterministicExtractor.php';
require_once __DIR__ . '/src/FaturaNf3e/Nf3eTableExtractor.php';
require_once __DIR__ . '/src/FaturaNf3e/Nf3eResultNormalizer.php';

function registrarDebug(string $nivel, string $mensagem, array $contexto = []): void
{
    // Mantém uma única instância do logger durante a requisição.
    static $logger = null;

    if ($logger === null) {
        $logger = new DebugLogger(__DIR__ . '/logs');
    }

    $logger->fRegistraEvento($nivel, $mensagem, $contexto);
}

function resumoDebug(?string $valor, int $limite = 8192): array
{
    // Evita gravar prompts, textos de PDF ou respostas grandes por inteiro no log
    return DebugLogger::fResumeTextoParaDebug($valor, $limite);
}

class processador_ia
{
    private $timeout_requisicao = 600;
    private $timeout_complemento = 120;

    private $cliente_ollama;
    private $construtor_prompt;
    private $filtro_texto;
    private $extrator_deterministico;
    private $extrator_tabulado;
    private $normalizador_resultado;
    private $limpador_json;

    // Campos mínimos para devolver resultado sem acionar a IA principal
    private const CAMPOS_ESSENCIAIS_RESPOSTA_RAPIDA = [
        'chave_acesso',
        'num_cnpj_emit',
        'num_nf',
        'referencia',
        'dat_emissao',
        'dat_vencimento',
        'val_total',
        'dat_leitura_anterior',
        'dat_leitura_atual',
        'dta_leitura_prox',
        'cod_subgrupo',
        'codigo_modalidade',
    ];

    public function __construct(
        ?OllamaClient $cliente_ollama = null,
        ?OllamaPromptBuilder $construtor_prompt = null,
        ?Nf3eInvoiceTextFilter $filtro_texto = null,
        ?Nf3eDeterministicExtractor $extrator_deterministico = null,
        ?Nf3eTableExtractor $extrator_tabulado = null,
        ?Nf3eResultNormalizer $normalizador_resultado = null,
        ?IaJsonResponseSanitizer $limpador_json = null
    ) {
        // Permite injetar dependências em testes, mas cria os componentes padrão no uso normal
        $this->cliente_ollama = $cliente_ollama ?: new OllamaClient();
        $this->construtor_prompt = $construtor_prompt ?: new OllamaPromptBuilder(__DIR__ . '/prompts/fatura_nf3e.txt');
        $this->filtro_texto = $filtro_texto ?: new Nf3eInvoiceTextFilter();
        $this->extrator_deterministico = $extrator_deterministico ?: new Nf3eDeterministicExtractor();
        $this->extrator_tabulado = $extrator_tabulado ?: new Nf3eTableExtractor();
        $this->normalizador_resultado = $normalizador_resultado ?: new Nf3eResultNormalizer();
        $this->limpador_json = $limpador_json ?: new IaJsonResponseSanitizer();
    }

    public function fProcessaTextoNf3e(string $texto, ?string $id_debug = null, bool $debug = true)
    {
        $id_debug = $id_debug ?: uniqid('ia_', true);

        if ($debug) {
            registrarDebug('info', 'IA: inicio do processamento', [
                'run_id' => $id_debug,
                'prompt_path' => $this->construtor_prompt->fCaminhoPrompt(),
                'input_text' => resumoDebug($texto, 2000),
            ]);
        }

        // Reduz o texto bruto aos trechos que ajudam a extração e o prompt
        $texto_filtrado = $this->filtro_texto->fFiltraTextoNf3e($texto);

        if ($debug) {
            registrarDebug('debug', 'IA: texto filtrado para prompt', [
                'run_id' => $id_debug,
                'original_text' => resumoDebug($texto, 2000),
                'filtered_text' => resumoDebug($texto_filtrado, 4000),
                'original_length' => strlen($texto),
                'filtered_length' => strlen($texto_filtrado),
            ]);
        }

        // Primeiro tenta extrair tudo por regras: campos fixos, produtos e histórico
        $campos_fixos = $this->extrator_deterministico->fExtraiCamposNf3e($texto);
        $campos_tabulados = $this->extrator_tabulado->fExtraiDadosTabela($texto_filtrado);
        $dados_deterministicos = array_merge($campos_tabulados, $campos_fixos);

        if ($debug) {
            registrarDebug('info', 'IA: dados determinísticos avaliados', [
                'run_id' => $id_debug,
                'fixed_fields' => array_keys($campos_fixos),
                'tabulated_fields' => array_keys($campos_tabulados),
                'product_count' => isset($campos_tabulados['produtos']) ? count($campos_tabulados['produtos']) : 0,
                'history_count' => isset($campos_tabulados['historico']) ? count($campos_tabulados['historico']) : 0,
            ]);
        }

        // Caminho rápido: se as regras já encontraram o essencial, não chama o modelo
        if ($this->fValidaRespostaDeterministica($dados_deterministicos)) {
            $resultado_deterministico = $this->normalizador_resultado->fNormalizaResultadoFinal($dados_deterministicos);

            if ($debug) {
                registrarDebug('info', 'IA: resposta gerada pelo caminho rápido determinístico', [
                    'run_id' => $id_debug,
                    'result_keys' => array_keys($resultado_deterministico),
                ]);
            }

            return $resultado_deterministico;
        }

        $campos_complementares = [];
        // Quando faltam poucos campos, usa uma chamada curta para complementar apenas eles
        $campos_ausentes = $this->fListaCamposEssenciaisAusentes($dados_deterministicos);

        if ($this->fValidaComplementoIa($dados_deterministicos, $campos_ausentes)) {
            $campos_complementares = $this->fExtraiCamposAusentesIa(
                $texto,
                $texto_filtrado,
                $campos_ausentes,
                $id_debug,
                $debug
            );

            $dados_deterministicos = $this->fAplicaComplementoIa($dados_deterministicos, $campos_complementares, $campos_ausentes);

            if ($debug) {
                registrarDebug('info', 'IA: complemento de campos ausentes finalizado', [
                    'run_id' => $id_debug,
                    'requested_fields' => $campos_ausentes,
                    'filled_fields' => array_keys($campos_complementares),
                    'still_missing_fields' => $this->fListaCamposEssenciaisAusentes($dados_deterministicos),
                ]);
            }

            // Se o complemento fechou todos os requisitos, também evita a chamada completa
            if ($this->fValidaRespostaDeterministica($dados_deterministicos)) {
                $resultado_complementado = $this->normalizador_resultado->fNormalizaResultadoFinal($dados_deterministicos);

                if ($debug) {
                    registrarDebug('info', 'IA: resposta gerada com complemento mínimo', [
                        'run_id' => $id_debug,
                        'result_keys' => array_keys($resultado_complementado),
                    ]);
                }

                return $resultado_complementado;
            }
        }


        // Fallback: monta o prompt completo quando o caminho determinístico não basta
        if (!file_exists($this->construtor_prompt->fCaminhoPrompt())) {
            if ($debug) {
                registrarDebug('error', 'IA: template de prompt nao encontrado', [
                    'run_id' => $id_debug,
                    'prompt_path' => $this->construtor_prompt->fCaminhoPrompt(),
                ]);
            }

            throw new Exception("Template de prompt não encontrado em: " . $this->construtor_prompt->fCaminhoPrompt());
        }

        $template = $this->construtor_prompt->fCarregaTemplate();

        if ($debug) {
            registrarDebug('debug', 'IA: template carregado', [
                'run_id' => $id_debug,
                'template' => resumoDebug($template, 2000),
                'template_has_placeholder' => strpos($template, '{{TEXTO_PDF}}') !== false,
            ]);
        }

        // O template define as regras; o texto filtrado entra no placeholder
        $prompt_completo = str_replace('{{TEXTO_PDF}}', $texto_filtrado, $template);

        if ($debug) {
            registrarDebug('debug', 'IA: prompt final montado', [
                'run_id' => $id_debug,
                'full_prompt' => resumoDebug($prompt_completo, 2000),
                'text_length' => strlen($texto),
                'filtered_text_length' => strlen($texto_filtrado),
            ]);
        }

        $json_bruto = $this->cliente_ollama->fGeraRespostaIa(
            $prompt_completo,
            $this->timeout_requisicao,
            4096,
            $this->cliente_ollama->fCalculaNumCtx($prompt_completo),
            $id_debug,
            $debug
        );

        if ($debug) {
            registrarDebug('debug', 'IA: campo response extraido', [
                'run_id' => $id_debug,
                'response_field' => resumoDebug($json_bruto, 2000),
            ]);
        }

        // A resposta pode vir com markdown ou texto extra; o sanitizer isola o JSON
        $json_limpo = $this->limpador_json->fExtraiJsonDaRespostaIa($json_bruto ?? '');
        $resultado_decodificado = json_decode($json_limpo, true);
        $erro_json_interno = json_last_error_msg();

        if ($debug) {
            registrarDebug(json_last_error() === JSON_ERROR_NONE ? 'info' : 'error', 'IA: JSON final decodificado', [
                'run_id' => $id_debug,
                'json_error' => $erro_json_interno,
                'clean_json' => resumoDebug($json_limpo, 2000),
                'result_type' => gettype($resultado_decodificado),
                'result_keys' => is_array($resultado_decodificado) ? array_keys($resultado_decodificado) : null,
            ]);
        }

        if (!is_array($resultado_decodificado)) {
            throw new Exception("IA retornou um JSON inválido ou fora do formato esperado.");
        }

        // Dados determinísticos têm prioridade sobre o retorno da IA
        $resultado_decodificado = $this->normalizador_resultado->fNormalizaChaves($resultado_decodificado);
        $resultado_decodificado = array_merge($resultado_decodificado, $campos_tabulados, $campos_fixos, $campos_complementares);
        $resultado_decodificado = $this->normalizador_resultado->fNormalizaResultadoFinal($resultado_decodificado);

        if ($debug) {
            registrarDebug('info', 'IA: campos determinísticos aplicados', [
                'run_id' => $id_debug,
                'fixed_fields' => $campos_fixos,
                'tabulated_fields' => array_keys($campos_tabulados),
                'result_keys' => array_keys($resultado_decodificado),
            ]);
        }

        return $resultado_decodificado;
    }

    private function fValidaRespostaDeterministica(array $dados): bool
    {
        // Variável de ambiente útil para testes comparativos contra o modelo
        $forcar_ia = getenv('EXTRATOR_FORCAR_IA');
        if (filter_var($forcar_ia, FILTER_VALIDATE_BOOLEAN)) return false;

        return $this->fListaCamposEssenciaisAusentes($dados) === [] && !empty($dados['produtos']) && is_array($dados['produtos']);
    }

    private function fListaCamposEssenciaisAusentes(array $dados): array
    {
        $ausentes = [];

        foreach (self::CAMPOS_ESSENCIAIS_RESPOSTA_RAPIDA as $campo) {
            if (empty($dados[$campo])) $ausentes[] = $campo;
        }

        return $ausentes;
    }

    private function fValidaComplementoIa(array $dados, array $campos_ausentes): bool
    {
        // Complemento só vale a pena quando já existe base tabulada e pouca coisa faltando
        $forcar_ia = getenv('EXTRATOR_FORCAR_IA');
        if (filter_var($forcar_ia, FILTER_VALIDATE_BOOLEAN)) return false;

        if ($campos_ausentes === [] || empty($dados['produtos']) || !is_array($dados['produtos'])) return false;

        return count($campos_ausentes) <= 4;
    }

    private function fAplicaComplementoIa(array $dados, array $complemento, array $campos_ausentes): array
    {
        // Preenche somente campos ainda vazios para não sobrescrever regras mais confiáveis
        foreach ($campos_ausentes as $campo) {
            if (empty($dados[$campo]) && !empty($complemento[$campo])) {
                $dados[$campo] = $complemento[$campo];
            }
        }

        return $dados;
    }

    private function fExtraiCamposAusentesIa(string $texto_original, string $texto_filtrado, array $campos_ausentes, string $id_debug, bool $debug): array
    {
        $prompt = $this->construtor_prompt->fMontaPromptCamposAusentes($texto_original, $texto_filtrado, $campos_ausentes);

        if ($debug) {
            registrarDebug('info', 'IA: chamada complementar iniciada', [
                'run_id' => $id_debug,
                'requested_fields' => $campos_ausentes,
                'prompt' => resumoDebug($prompt, 2000),
                'payload_prompt_length' => strlen($prompt),
            ]);
        }

        $json_bruto = $this->cliente_ollama->fGeraRespostaIa(
            $prompt,
            $this->timeout_complemento,
            512,
            min(4096, $this->cliente_ollama->fCalculaNumCtx($prompt)),
            $id_debug,
            $debug,
            'IA: chamada complementar',
            true
        );

        if ($json_bruto === null) return [];

        // Só aproveita chaves que estavam na lista de ausentes solicitada
        $json_limpo = $this->limpador_json->fExtraiJsonDaRespostaIa($json_bruto);
        $resultado = json_decode($json_limpo, true);
        if (!is_array($resultado)) return [];

        $resultado = $this->normalizador_resultado->fNormalizaChaves($resultado);
        $complemento = [];

        foreach ($campos_ausentes as $campo) {
            if (!empty($resultado[$campo])) {
                $complemento[$campo] = trim((string) $resultado[$campo]);
            }
        }

        return $complemento;
    }
}
