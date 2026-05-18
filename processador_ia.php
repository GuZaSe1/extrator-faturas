<?php

require_once __DIR__ . '/src/Debug/DebugLogger.php';
require_once __DIR__ . '/src/Ollama/OllamaClient.php';
require_once __DIR__ . '/src/Ollama/OllamaPromptBuilder.php';
require_once __DIR__ . '/src/Ollama/IaJsonResponseSanitizer.php';
require_once __DIR__ . '/src/FaturaNf3e/Nf3eInvoiceTextFilter.php';
require_once __DIR__ . '/src/FaturaNf3e/Nf3eDeterministicExtractor.php';
require_once __DIR__ . '/src/FaturaNf3e/Nf3eTableExtractor.php';
require_once __DIR__ . '/src/FaturaNf3e/Nf3eResultNormalizer.php';

function app_logger(): DebugLogger
{
    static $logger = null;

    if ($logger === null) {
        $logger = new DebugLogger(__DIR__ . '/logs');
    }

    return $logger;
}

function registrarDebug(string $nivel, string $mensagem, array $contexto = []): void
{
    app_logger()->registrar($nivel, $mensagem, $contexto);
}

function resumoDebug(?string $valor, int $limite = 8192): array
{
    return DebugLogger::resumir($valor, $limite);
}

class processador_ia
{
    private $tempo_requisicao_segundos = 600;
    private $tempo_complemento_segundos = 120;

    private $cliente_ollama;
    private $construtor_prompt;
    private $filtro_texto;
    private $extrator_deterministico;
    private $extrator_tabulado;
    private $normalizador_resultado;
    private $limpador_json;

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
        $this->cliente_ollama = $cliente_ollama ?: new OllamaClient();
        $this->construtor_prompt = $construtor_prompt ?: new OllamaPromptBuilder(__DIR__ . '/prompts/fatura_nf3e.txt');
        $this->filtro_texto = $filtro_texto ?: new Nf3eInvoiceTextFilter();
        $this->extrator_deterministico = $extrator_deterministico ?: new Nf3eDeterministicExtractor();
        $this->extrator_tabulado = $extrator_tabulado ?: new Nf3eTableExtractor();
        $this->normalizador_resultado = $normalizador_resultado ?: new Nf3eResultNormalizer();
        $this->limpador_json = $limpador_json ?: new IaJsonResponseSanitizer();
    }

    public function processarTextoFatura(string $texto, ?string $id_debug = null, bool $debug = true)
    {
        $id_debug = $id_debug ?: uniqid('ia_', true);
        $inicio_processamento = microtime(true);

        if ($debug) {
            registrarDebug('info', 'IA: inicio do processamento', [
                'run_id' => $id_debug,
                'api_url' => $this->cliente_ollama->urlApi(),
                'model' => $this->cliente_ollama->modelo(),
                'prompt_path' => $this->construtor_prompt->caminhoPrompt(),
                'input_text' => resumoDebug($texto, 2000),
            ]);
        }

        $inicio_filtro = microtime(true);
        $texto_filtrado = $this->filtro_texto->fFiltrar($texto);

        if ($debug) {
            registrarDebug('debug', 'IA: texto filtrado para prompt', [
                'run_id' => $id_debug,
                'original_text' => resumoDebug($texto, 2000),
                'filtered_text' => resumoDebug($texto_filtrado, 4000),
                'original_length' => strlen($texto),
                'filtered_length' => strlen($texto_filtrado),
                'step_duration_seconds' => $this->segundosDesde($inicio_filtro),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $inicio_deterministico = microtime(true);
        $campos_fixos = $this->extrator_deterministico->fExtrair($texto);
        $campos_tabulados = $this->extrator_tabulado->fExtrair($texto_filtrado);
        $dados_deterministicos = array_merge($campos_tabulados, $campos_fixos);

        if ($debug) {
            registrarDebug('info', 'IA: dados determinísticos avaliados', [
                'run_id' => $id_debug,
                'fixed_fields' => array_keys($campos_fixos),
                'tabulated_fields' => array_keys($campos_tabulados),
                'product_count' => isset($campos_tabulados['produtos']) ? count($campos_tabulados['produtos']) : 0,
                'history_count' => isset($campos_tabulados['historico']) ? count($campos_tabulados['historico']) : 0,
                'step_duration_seconds' => $this->segundosDesde($inicio_deterministico),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        if ($this->podeResponderSemIa($dados_deterministicos)) {
            $resultado_deterministico = $this->normalizador_resultado->fNormalizarResultadoFinal($dados_deterministicos);

            if ($debug) {
                registrarDebug('info', 'IA: resposta gerada pelo caminho rápido determinístico', [
                    'run_id' => $id_debug,
                    'result_keys' => array_keys($resultado_deterministico),
                    'duration_seconds' => $this->segundosDesde($inicio_processamento),
                ]);
            }

            return $resultado_deterministico;
        }

        $campos_complementares = [];
        $campos_ausentes = $this->camposEssenciaisAusentes($dados_deterministicos);

        if ($this->podeTentarComplementoIa($dados_deterministicos, $campos_ausentes)) {
            $inicio_complemento = microtime(true);
            $campos_complementares = $this->extrairCamposAusentesComIa(
                $texto,
                $texto_filtrado,
                $campos_ausentes,
                $id_debug,
                $debug,
                $inicio_processamento
            );

            $dados_deterministicos = $this->aplicarComplementoIa($dados_deterministicos, $campos_complementares, $campos_ausentes);

            if ($debug) {
                registrarDebug('info', 'IA: complemento de campos ausentes finalizado', [
                    'run_id' => $id_debug,
                    'requested_fields' => $campos_ausentes,
                    'filled_fields' => array_keys($campos_complementares),
                    'still_missing_fields' => $this->camposEssenciaisAusentes($dados_deterministicos),
                    'step_duration_seconds' => $this->segundosDesde($inicio_complemento),
                    'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
                ]);
            }

            if ($this->podeResponderSemIa($dados_deterministicos)) {
                $resultado_complementado = $this->normalizador_resultado->fNormalizarResultadoFinal($dados_deterministicos);

                if ($debug) {
                    registrarDebug('info', 'IA: resposta gerada com complemento mínimo', [
                        'run_id' => $id_debug,
                        'result_keys' => array_keys($resultado_complementado),
                        'duration_seconds' => $this->segundosDesde($inicio_processamento),
                    ]);
                }

                return $resultado_complementado;
            }
        }

        $inicio_template = microtime(true);

        if (!file_exists($this->construtor_prompt->caminhoPrompt())) {
            if ($debug) {
                registrarDebug('error', 'IA: template de prompt nao encontrado', [
                    'run_id' => $id_debug,
                    'prompt_path' => $this->construtor_prompt->caminhoPrompt(),
                    'step_duration_seconds' => $this->segundosDesde($inicio_template),
                    'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
                ]);
            }

            throw new Exception("Template de prompt não encontrado em: " . $this->construtor_prompt->caminhoPrompt());
        }

        $template = $this->construtor_prompt->carregarTemplate();

        if ($debug) {
            registrarDebug('debug', 'IA: template carregado', [
                'run_id' => $id_debug,
                'template' => resumoDebug($template, 2000),
                'template_has_placeholder' => strpos($template, '{{TEXTO_PDF}}') !== false,
                'step_duration_seconds' => $this->segundosDesde($inicio_template),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $inicio_prompt = microtime(true);
        $prompt_completo = str_replace('{{TEXTO_PDF}}', $texto_filtrado, $template);

        if ($debug) {
            registrarDebug('debug', 'IA: prompt final montado', [
                'run_id' => $id_debug,
                'full_prompt' => resumoDebug($prompt_completo, 2000),
                'text_length' => strlen($texto),
                'filtered_text_length' => strlen($texto_filtrado),
                'step_duration_seconds' => $this->segundosDesde($inicio_prompt),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $json_bruto = $this->cliente_ollama->gerar(
            $prompt_completo,
            $this->tempo_requisicao_segundos,
            4096,
            $this->cliente_ollama->calcularNumCtx($prompt_completo),
            $id_debug,
            $debug,
            $inicio_processamento
        );

        if ($debug) {
            registrarDebug('debug', 'IA: campo response extraido', [
                'run_id' => $id_debug,
                'response_field' => resumoDebug($json_bruto, 2000),
            ]);
        }

        $inicio_decode_resultado = microtime(true);
        $json_limpo = $this->limpador_json->limpar($json_bruto ?? '');
        $resultado_decodificado = json_decode($json_limpo, true);
        $erro_json_interno = json_last_error_msg();

        if ($debug) {
            registrarDebug(json_last_error() === JSON_ERROR_NONE ? 'info' : 'error', 'IA: JSON final decodificado', [
                'run_id' => $id_debug,
                'json_error' => $erro_json_interno,
                'clean_json' => resumoDebug($json_limpo, 2000),
                'result_type' => gettype($resultado_decodificado),
                'result_keys' => is_array($resultado_decodificado) ? array_keys($resultado_decodificado) : null,
                'step_duration_seconds' => $this->segundosDesde($inicio_decode_resultado),
                'duration_seconds' => round(microtime(true) - $inicio_processamento, 4),
            ]);
        }

        if (!is_array($resultado_decodificado)) {
            throw new Exception("IA retornou um JSON inválido ou fora do formato esperado.");
        }

        $inicio_campos_deterministicos = microtime(true);
        $resultado_decodificado = $this->normalizador_resultado->fNormalizarChaves($resultado_decodificado);
        $resultado_decodificado = array_merge($resultado_decodificado, $campos_tabulados, $campos_fixos, $campos_complementares);
        $resultado_decodificado = $this->normalizador_resultado->fNormalizarResultadoFinal($resultado_decodificado);

        if ($debug) {
            registrarDebug('info', 'IA: campos determinísticos aplicados', [
                'run_id' => $id_debug,
                'fixed_fields' => $campos_fixos,
                'tabulated_fields' => array_keys($campos_tabulados),
                'result_keys' => array_keys($resultado_decodificado),
                'step_duration_seconds' => $this->segundosDesde($inicio_campos_deterministicos),
                'duration_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        return $resultado_decodificado;
    }

    private function segundosDesde(float $inicio): float
    {
        return round(microtime(true) - $inicio, 4);
    }

    private function podeResponderSemIa(array $dados): bool
    {
        $forcar_ia = getenv('EXTRATOR_FORCAR_IA');
        if (filter_var($forcar_ia, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return $this->camposEssenciaisAusentes($dados) === []
            && !empty($dados['produtos'])
            && is_array($dados['produtos']);
    }

    private function camposEssenciaisAusentes(array $dados): array
    {
        $ausentes = [];

        foreach (self::CAMPOS_ESSENCIAIS_RESPOSTA_RAPIDA as $campo) {
            if (empty($dados[$campo])) {
                $ausentes[] = $campo;
            }
        }

        return $ausentes;
    }

    private function podeTentarComplementoIa(array $dados, array $campos_ausentes): bool
    {
        $forcar_ia = getenv('EXTRATOR_FORCAR_IA');
        if (filter_var($forcar_ia, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        if ($campos_ausentes === [] || empty($dados['produtos']) || !is_array($dados['produtos'])) {
            return false;
        }

        return count($campos_ausentes) <= 4;
    }

    private function aplicarComplementoIa(array $dados, array $complemento, array $campos_ausentes): array
    {
        foreach ($campos_ausentes as $campo) {
            if (empty($dados[$campo]) && !empty($complemento[$campo])) {
                $dados[$campo] = $complemento[$campo];
            }
        }

        return $dados;
    }

    private function extrairCamposAusentesComIa(string $texto_original, string $texto_filtrado, array $campos_ausentes, string $id_debug, bool $debug, float $inicio_processamento): array
    {
        $prompt = $this->construtor_prompt->montarPromptCamposAusentes($texto_original, $texto_filtrado, $campos_ausentes);

        if ($debug) {
            registrarDebug('info', 'IA: chamada complementar iniciada', [
                'run_id' => $id_debug,
                'requested_fields' => $campos_ausentes,
                'prompt' => resumoDebug($prompt, 2000),
                'payload_prompt_length' => strlen($prompt),
                'request_timeout_seconds' => $this->tempo_complemento_segundos,
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $json_bruto = $this->cliente_ollama->gerar(
            $prompt,
            $this->tempo_complemento_segundos,
            512,
            min(4096, $this->cliente_ollama->calcularNumCtx($prompt)),
            $id_debug,
            $debug,
            $inicio_processamento,
            'IA: chamada complementar',
            true
        );

        if ($json_bruto === null) return [];

        $json_limpo = $this->limpador_json->limpar($json_bruto);
        $resultado = json_decode($json_limpo, true);
        if (!is_array($resultado)) return [];

        $resultado = $this->normalizador_resultado->fNormalizarChaves($resultado);
        $complemento = [];

        foreach ($campos_ausentes as $campo) {
            if (!empty($resultado[$campo])) {
                $complemento[$campo] = trim((string) $resultado[$campo]);
            }
        }

        return $complemento;
    }
}
