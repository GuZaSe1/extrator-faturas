<?php

function registrarDebug(string $nivel, string $mensagem, array $contexto = []): void
{
    $diretorio_logs = __DIR__ . '/logs';

    if (!is_dir($diretorio_logs)) mkdir($diretorio_logs, 0775, true);

    $linha = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($nivel),
        $mensagem,
        $contexto ? json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );

    file_put_contents($diretorio_logs . '/app.log', $linha, FILE_APPEND | LOCK_EX);
}

function resumoDebug(?string $valor, int $limite = 8192): array
{
    $valor = $valor ?? '';
    $tamanho = strlen($valor);

    return [
        'length' => $tamanho,
        'excerpt' => substr($valor, 0, $limite),
        'truncated' => $tamanho > $limite,
    ];
}

class processador_ia
{
    private $url_api = "http://localhost:11434/api/generate";
    private $modelo = "qwen2.5:7b";
    private $caminho_prompt = __DIR__ . "/prompts/fatura_nf3e.txt";
    private $tempo_conexao_segundos = 10;
    private $tempo_requisicao_segundos = 600;
    private $tempo_complemento_segundos = 120;
    private static $template_prompt_cache = null;

    private const CAMPOS_OBRIGATORIOS = [
        'chave_acesso' => null,
        'num_cnpj_emit' => null,
        'num_cnpj_dest' => null,
        'num_nf' => null,
        'referencia' => null,
        'cod_unidade_consumo' => null,
        'dat_emissao' => null,
        'cod_subgrupo' => null,
        'codigo_modalidade' => null,
        'val_total' => null,
        'dat_leitura_anterior' => null,
        'dat_leitura_atual' => null,
        'dta_leitura_prox' => null,
        'dat_vencimento' => null,
        'demanda_contratada_fp' => null,
        'demanda_contratada_p' => null,
        'pct_cofins' => null,
        'pct_pis' => null,
        'produtos' => [],
        'historico' => [],
    ];

    private const CAMPOS_ESSENCIAIS_RESPOSTA_RAPIDA = [
        'chave_acesso',
        'num_cnpj_emit',
        'num_nf',
        'referencia',
        'cod_unidade_consumo',
        'dat_emissao',
        'dat_vencimento',
        'val_total',
        'dat_leitura_anterior',
        'dat_leitura_atual',
        'dta_leitura_prox',
        'cod_subgrupo',
        'codigo_modalidade',
    ];

    public function processarTextoFatura(string $texto, ?string $id_debug = null, bool $debug = true)
    {
        $id_debug = $id_debug ?: uniqid('ia_', true);
        $inicio_processamento = microtime(true);

        if ($debug) {
            registrarDebug('info', 'IA: inicio do processamento', [
                'run_id' => $id_debug,
                'api_url' => $this->url_api,
                'model' => $this->modelo,
                'prompt_path' => $this->caminho_prompt,
                'input_text' => resumoDebug($texto, 2000),
            ]);
        }

        // 1. Reduz o texto bruto antes de enviar para a IA, preservando o original
        // para as extrações determinísticas feitas depois da resposta.
        $inicio_filtro = microtime(true);
        $texto_filtrado = $this->filtrarTextoFatura($texto);

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
        $campos_fixos = $this->extrairCamposFixos($texto);
        $campos_tabulados = $this->extrairDadosTabulados($texto_filtrado);
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
            $resultado_deterministico = $this->normalizarResultadoFinal($dados_deterministicos);

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
                $resultado_complementado = $this->normalizarResultadoFinal($dados_deterministicos);

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

        // 2. Carrega o template apenas quando o fallback por IA for necessário.
        $inicio_template = microtime(true);

        if (!file_exists($this->caminho_prompt)) {
            if ($debug) {
                registrarDebug('error', 'IA: template de prompt nao encontrado', [
                    'run_id' => $id_debug,
                    'prompt_path' => $this->caminho_prompt,
                    'step_duration_seconds' => $this->segundosDesde($inicio_template),
                    'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
                ]);
            }

            throw new Exception("Template de prompt não encontrado em: " . $this->caminho_prompt);
        }

        $template = $this->carregarTemplatePrompt();

        if ($debug) {
            registrarDebug('debug', 'IA: template carregado', [
                'run_id' => $id_debug,
                'template' => resumoDebug($template, 2000),
                'template_has_placeholder' => strpos($template, '{{TEXTO_PDF}}') !== false,
                'step_duration_seconds' => $this->segundosDesde($inicio_template),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        // 3. Substitui o placeholder pelo texto filtrado do PDF
        $inicio_prompt = microtime(true);
        $prompt_completo = str_replace("{{TEXTO_PDF}}", $texto_filtrado, $template);

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

        // 4. Monta o payload para o Ollama
        $inicio_payload = microtime(true);
        $payload = [
            "model" => $this->modelo,
            "prompt" => $prompt_completo,
            "stream" => false,
            "format" => "json",
            "keep_alive" => "30m",
            "options" => [
                "temperature" => 0,
                "num_ctx" => $this->calcularNumCtx($prompt_completo),
                "num_predict" => 4096,
                "num_thread" => 6
            ]
        ];

        if ($debug) {
            registrarDebug('debug', 'IA: payload montado para Ollama', [
                'run_id' => $id_debug,
                'payload_model' => $payload['model'],
                'payload_stream' => $payload['stream'],
                'payload_format' => $payload['format'],
                'payload_options' => $payload['options'],
                'payload_prompt_length' => strlen($payload['prompt']),
                'connect_timeout_seconds' => $this->tempo_conexao_segundos,
                'request_timeout_seconds' => $this->tempo_requisicao_segundos,
                'step_duration_seconds' => $this->segundosDesde($inicio_payload),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $inicio_chamada_http = microtime(true);
        $curl = curl_init($this->url_api);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->tempo_conexao_segundos);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->tempo_requisicao_segundos);
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);

        if ($debug) {
            registrarDebug('info', 'IA: chamada cURL iniciada', [
                'run_id' => $id_debug,
                'api_url' => $this->url_api,
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $resposta = curl_exec($curl);
        $erro_curl = curl_error($curl);
        $codigo_erro_curl = curl_errno($curl);
        $codigo_http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $tipo_conteudo = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $tempo_total = curl_getinfo($curl, CURLINFO_TOTAL_TIME);
        curl_close($curl);

        if ($debug) {
            registrarDebug($codigo_erro_curl ? 'error' : 'info', 'IA: chamada cURL finalizada', [
                'run_id' => $id_debug,
                'curl_errno' => $codigo_erro_curl,
                'curl_error' => $erro_curl,
                'http_code' => $codigo_http,
                'content_type' => $tipo_conteudo,
                'curl_total_time_seconds' => $tempo_total,
                'step_duration_seconds' => $this->segundosDesde($inicio_chamada_http),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
                'raw_response' => resumoDebug($resposta === false ? '' : $resposta, 2000),
            ]);
        }

        if ($resposta === false) {
            if ($codigo_erro_curl === CURLE_OPERATION_TIMEDOUT) {
                throw new Exception(
                    "Erro ao chamar Ollama: a geração excedeu {$this->tempo_requisicao_segundos}s sem resposta. " .
                        "O modelo {$this->modelo} pode estar lento para este prompt; tente novamente, use um modelo menor ou aumente o timeout em processador_ia.php."
                );
            }

            throw new Exception("Erro ao chamar Ollama: " . ($erro_curl ?: 'erro desconhecido'));
        }

        if ($codigo_http < 200 || $codigo_http >= 300) {
            throw new Exception("Erro ao chamar Ollama: HTTP {$codigo_http} - " . substr($resposta, 0, 500));
        }

        $inicio_decode_http = microtime(true);
        $dados_resposta = json_decode($resposta, true);
        $erro_json_externo = json_last_error_msg();

        if ($debug) {
            registrarDebug(json_last_error() === JSON_ERROR_NONE ? 'debug' : 'error', 'IA: resposta HTTP decodificada', [
                'run_id' => $id_debug,
                'json_error' => $erro_json_externo,
                'decoded_keys' => is_array($dados_resposta) ? array_keys($dados_resposta) : null,
                'step_duration_seconds' => $this->segundosDesde($inicio_decode_http),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $json_bruto = $dados_resposta['response'] ?? '';

        if ($debug) {
            registrarDebug('debug', 'IA: campo response extraido', [
                'run_id' => $id_debug,
                'response_field' => resumoDebug($json_bruto, 2000),
            ]);
        }

        // Limpeza de segurança para as barras e cercas que a IA costuma adicionar.
        $inicio_decode_resultado = microtime(true);
        $json_limpo = $this->limparJsonGerado($json_bruto);

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
        $resultado_decodificado = $this->normalizarChavesResultado($resultado_decodificado);
        $resultado_decodificado = array_merge($resultado_decodificado, $campos_tabulados, $campos_fixos, $campos_complementares);
        $resultado_decodificado = $this->normalizarResultadoFinal($resultado_decodificado);

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

    private function normalizarChavesResultado(array $resultado): array
    {
        $resultado_normalizado = [];

        foreach ($resultado as $chave => $valor) {
            $resultado_normalizado[str_replace('-', '_', (string) $chave)] = $valor;
        }

        return $resultado_normalizado;
    }

    private function segundosDesde(float $inicio): float
    {
        return round(microtime(true) - $inicio, 4);
    }

    private function carregarTemplatePrompt(): string
    {
        if (self::$template_prompt_cache !== null) {
            return self::$template_prompt_cache;
        }

        $template = file_get_contents($this->caminho_prompt);
        if ($template === false) {
            throw new Exception("Não foi possível ler o template de prompt em: " . $this->caminho_prompt);
        }

        self::$template_prompt_cache = $template;

        return $template;
    }

    private function calcularNumCtx(string $prompt): int
    {
        $tokens_estimados = (int) ceil(strlen($prompt) / 3);

        if ($tokens_estimados <= 2500) return 4096;
        if ($tokens_estimados <= 6000) return 8192;

        return 12288;
    }

    private function limparJsonGerado(string $json_bruto): string
    {
        $json = trim(str_replace('\/', '/', $json_bruto));
        $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
        $json = preg_replace('/\s*```$/', '', $json);

        if (json_decode($json, true) !== null && json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        $inicio = strpos($json, '{');
        $fim = strrpos($json, '}');

        if ($inicio !== false && $fim !== false && $fim > $inicio) {
            return substr($json, $inicio, $fim - $inicio + 1);
        }

        return $json;
    }

    private function normalizarResultadoFinal(array $resultado): array
    {
        $resultado['produtos'] = $this->normalizarProdutos($resultado['produtos'] ?? []);
        $resultado['historico'] = $this->normalizarHistorico($resultado['historico'] ?? []);

        $ordenado = [];
        foreach (self::CAMPOS_OBRIGATORIOS as $campo => $padrao) {
            $ordenado[$campo] = array_key_exists($campo, $resultado) ? $resultado[$campo] : $padrao;
        }

        foreach ($resultado as $campo => $valor) {
            if (!array_key_exists($campo, $ordenado)) {
                $ordenado[$campo] = $valor;
            }
        }

        return $ordenado;
    }

    private function normalizarProdutos($produtos): array
    {
        if (!is_array($produtos)) return [];

        $normalizados = [];

        foreach ($produtos as $produto) {
            if (!is_array($produto)) continue;

            $descricao = trim((string) ($produto['descricao'] ?? ''));
            if ($descricao === '' || $this->ehLinhaNaoProduto($descricao)) continue;

            $normalizados[] = [
                'descricao' => $descricao,
                'unidade' => $this->normalizarValorVazio($produto['unidade'] ?? null, true),
                'quantidade' => $this->normalizarValorVazio($produto['quantidade'] ?? null),
                'preco' => $this->normalizarValorVazio($produto['preco'] ?? null),
                'valor' => $this->normalizarValorVazio($produto['valor'] ?? null),
            ];
        }

        return $normalizados;
    }

    private function normalizarHistorico($historico): array
    {
        if (!is_array($historico)) return [];

        $normalizados = [];

        foreach ($historico as $item) {
            if (!is_array($item)) continue;

            $descricao = trim((string) ($item['descricao'] ?? ''));
            if ($descricao === '') continue;

            $normalizados[] = [
                'descricao' => $descricao,
                'consumoFP' => $this->normalizarValorVazio($item['consumoFP'] ?? null),
                'consumoP' => $this->normalizarValorVazio($item['consumoP'] ?? null),
                'demandaFP' => $this->normalizarValorVazio($item['demandaFP'] ?? null),
                'demandaP' => $this->normalizarValorVazio($item['demandaP'] ?? null),
                'consumoRE' => $this->normalizarValorVazio($item['consumoRE'] ?? null),
            ];
        }

        return $normalizados;
    }

    private function normalizarValorVazio($valor, bool $maiusculo = false)
    {
        if ($valor === null) return null;

        $valor = trim((string) $valor);
        if ($valor === '') return null;

        return $maiusculo ? strtoupper($valor) : $valor;
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
        $prompt = $this->montarPromptCamposAusentes($texto_original, $texto_filtrado, $campos_ausentes);
        $payload = [
            "model" => $this->modelo,
            "prompt" => $prompt,
            "stream" => false,
            "format" => "json",
            "keep_alive" => "30m",
            "options" => [
                "temperature" => 0,
                "num_ctx" => min(4096, $this->calcularNumCtx($prompt)),
                "num_predict" => 512,
                "num_thread" => 6
            ]
        ];

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

        $inicio_chamada = microtime(true);
        $curl = curl_init($this->url_api);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->tempo_conexao_segundos);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->tempo_complemento_segundos);
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);

        $resposta = curl_exec($curl);
        $erro_curl = curl_error($curl);
        $codigo_erro_curl = curl_errno($curl);
        $codigo_http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $tempo_total = curl_getinfo($curl, CURLINFO_TOTAL_TIME);
        curl_close($curl);

        if ($debug) {
            registrarDebug($codigo_erro_curl ? 'error' : 'info', 'IA: chamada complementar finalizada', [
                'run_id' => $id_debug,
                'curl_errno' => $codigo_erro_curl,
                'curl_error' => $erro_curl,
                'http_code' => $codigo_http,
                'curl_total_time_seconds' => $tempo_total,
                'step_duration_seconds' => $this->segundosDesde($inicio_chamada),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
                'raw_response' => resumoDebug($resposta === false ? '' : $resposta, 1000),
            ]);
        }

        if ($resposta === false || $codigo_http < 200 || $codigo_http >= 300) return [];

        $dados_resposta = json_decode($resposta, true);
        if (!is_array($dados_resposta)) return [];

        $json_limpo = $this->limparJsonGerado($dados_resposta['response'] ?? '');
        $resultado = json_decode($json_limpo, true);
        if (!is_array($resultado)) return [];

        $resultado = $this->normalizarChavesResultado($resultado);
        $complemento = [];

        foreach ($campos_ausentes as $campo) {
            if (!empty($resultado[$campo])) {
                $complemento[$campo] = trim((string) $resultado[$campo]);
            }
        }

        return $complemento;
    }

    private function montarPromptCamposAusentes(string $texto_original, string $texto_filtrado, array $campos_ausentes): string
    {
        $json_chaves = json_encode(array_fill_keys($campos_ausentes, null), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $trechos_relevantes = $this->extrairTrechosRelevantesParaCampos($texto_original, $campos_ausentes);

        return "Você é um extrator de campos faltantes de faturas de energia NF3-e.\n"
            . "Retorne APENAS um JSON válido com exatamente estas chaves:\n"
            . $json_chaves . "\n\n"
            . "Regras:\n"
            . "- Não escreva explicações, markdown ou texto fora do JSON.\n"
            . "- Se não encontrar um campo, use null.\n"
            . "- Preserve zeros à esquerda.\n"
            // . "- cod_unidade_consumo é o código da unidade consumidora. Priorize o número depois de \"Número UC\".\n"
            // . "- cod_unidade_consumo não é chave de acesso, linha digitável, código PIX, data ou valor monetário.\n\n"
            . "TRECHOS RELEVANTES:\n"
            . $trechos_relevantes . "\n\n"
            . "TEXTO FILTRADO:\n"
            . substr($texto_filtrado, 0, 6000);
    }

    private function extrairTrechosRelevantesParaCampos(string $texto, array $campos_ausentes): string
    {
        $texto_normalizado = $this->normalizarTexto($texto);
        $linhas = explode("\n", $texto_normalizado);
        $linhas_relevantes = [];
        $manter_proximas = 0;

        $padroes = [
            '/N[úu]mero\s+UC|\bUC\b|Unidade\s+consumidora|N[ºo]\s+do\s+cliente/iu',
            '/\b\d{4,20}\s*\/\s*\d{4,20}\b/u',
            '/R\$\s*[*.0-9]*,\d{2}/u',
            '/NOTA\s+FISCAL|REFER[ÊE]NCIA|DATA\s+DE\s+EMISS[ÃA]O|DATA\s+DE\s+VENCIMENTO|VALOR\s+DO\s+DOCUMENTO/iu',
            '/Classifica[çc][ãa]o|SUBGRUPO|MODALIDADE|THS_(?:VERDE|AZUL)|\b[A-B]\s*-\s*[AB][1-4]/iu',
        ];

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '') continue;

            $manter = $manter_proximas > 0;
            foreach ($padroes as $padrao) {
                if (preg_match($padrao, $linha)) {
                    $manter = true;
                    $manter_proximas = max($manter_proximas, 2);
                    break;
                }
            }

            if ($manter) $linhas_relevantes[] = $linha;
            if ($manter_proximas > 0) $manter_proximas--;
        }

        $trechos = implode("\n", array_slice(array_values(array_unique($linhas_relevantes)), 0, 80));

        return $trechos !== '' ? $trechos : substr($texto_normalizado, 0, 3000);
    }

    private function extrairCamposFixos(string $texto): array
    {
        $campos = [];
        $texto_normalizado = $this->normalizarTexto($texto);

        $chave_acesso = $this->extrairChaveAcesso($texto);
        if ($chave_acesso !== null) {
            $campos['chave_acesso'] = $chave_acesso;

            if (strlen($chave_acesso) === 44) {
                $campos['num_cnpj_emit'] = substr($chave_acesso, 6, 14);
                $campos['serie'] = substr($chave_acesso, 22, 3);
                $campos['num_nf'] = substr($chave_acesso, 25, 9);
            }
        }

        $nota_fiscal = $this->buscarPrimeiro($texto_normalizado, [
            '/NOTA\s+FISCAL\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})/iu',
            '/\bNF\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})/iu',
        ]);

        if ($nota_fiscal !== null) {
            $campos['num_nf'] = $nota_fiscal;
        }

        $serie = $this->buscarPrimeiro($texto_normalizado, [
            '/S[ÉE]RIE\s*[-:]?\s*(\d{1,3})/iu',
        ]);

        if ($serie !== null) {
            $campos['serie'] = str_pad($serie, 3, '0', STR_PAD_LEFT);
        }

        $cabecalho = $this->extrairCabecalhoFatura($texto_normalizado);
        if ($cabecalho !== []) {
            $campos = array_merge($campos, $cabecalho);
        }

        $resumo_pagamento = $this->extrairResumoPagamento($texto_normalizado);
        if ($resumo_pagamento !== []) {
            $campos = array_merge($campos, $resumo_pagamento);
        }

        $cnpj_destinatario = $this->buscarPrimeiro($texto_normalizado, [
            '/(?:PAGADOR\s*\/\s*CPF:.*?|PAGADOR:.*?|CLIENTE:.*?|CONSUMIDOR:.*?|DESTINAT[ÁA]RIO:.*?)CNPJ(?:\/CPF)?\s*[:\-]?\s*([0-9*.]{2}\.[0-9*.]{3}\.[0-9*.]{3}\/[0-9*.]{4}-[0-9*.]{2})/ius',
            '/CNPJ(?:\/CPF)?\s*[:\-]?\s*([0-9*.]{2}\.[0-9*.]{3}\.[0-9*.]{3}\/[0-9*.]{4}-[0-9*.]{2})/iu',
            '/CNPJ(?:\/CPF)?\s*[:\-]?\s*([0-9*]{14})/iu',
        ]);

        if ($cnpj_destinatario !== null && (!isset($campos['num_cnpj_emit']) || $this->somenteDigitos($cnpj_destinatario) !== $campos['num_cnpj_emit'])) {
            $campos['num_cnpj_dest'] = $cnpj_destinatario;
        }

        $unidade_consumo = $this->buscarPrimeiro($texto_normalizado, [
            '/N[ÚU]MERO\s+UC[^\n]*(?:\n[^\n]*){0,4}\n\s*(\d{4,20})\s*\/\s*\d{4,20}/iu',
            '/N[ÚU]MERO\s+UC\s+(\d{4,20})(?:\s*\/\s*\d{4,20})?/iu',
            '/\bUC\s*[:\-]?\s*(\d{4,20})(?:\s*\/\s*\d{4,20})?/iu',
            '/UNIDADE\s+CONSUMIDORA\s*[:\-]?\s*(\d{4,20})/iu',
            '/(?:^|\n)\s*(\d{4,20})\s*\/\s*\d{4,20}\s+R\$\s*[*.0-9]*,\d{2}/u',
        ]);

        if ($unidade_consumo !== null) {
            $campos['cod_unidade_consumo'] = $unidade_consumo;
        }

        $leituras = $this->extrairDatasLeitura($texto_normalizado);
        if ($leituras !== []) {
            $campos = array_merge($campos, $leituras);
        }

        $pct_pis = $this->extrairPercentualTributo($texto_normalizado, '(?:PIS(?:\/PASEP)?|PASEP)');
        if ($pct_pis !== null) {
            $campos['pct_pis'] = $pct_pis;
        }

        $pct_cofins = $this->extrairPercentualTributo($texto_normalizado, 'COFINS');
        if ($pct_cofins !== null) {
            $campos['pct_cofins'] = $pct_cofins;
        }

        $classificacao = $this->extrairClassificacao($texto_normalizado);
        if ($classificacao !== []) {
            $campos = array_merge($campos, $classificacao);
        }

        return $campos;
    }

    private function extrairCabecalhoFatura(string $texto): array
    {
        $campos = [];

        if (preg_match('/NOTA\s+FISCAL\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})\s*-\s*S[ÉE]RIE\s*(\d{1,3})\s*\/\s*DATA\s+DE\s+EMISS[ÃA]O\s*:\s*(\d{2}\/\d{2}\/\d{4})/iu', $texto, $resultado)) {
            $campos['num_nf'] = $resultado[1];
            $campos['serie'] = str_pad($resultado[2], 3, '0', STR_PAD_LEFT);
            $campos['dat_emissao'] = $resultado[3];
        }

        if (preg_match('/DATA\s+DE\s+EMISS[ÃA]O:\s*NOTA\s+FISCAL:\s*REFER[ÊE]NCIA:\s*DATA\s+DE\s+VENCIMENTO:\s*VALOR\s+DO\s+DOCUMENTO:\s*\n\s*(\d{2}\/\d{2}\/\d{4})\s+(\d{6,12})\s+(\d{2}\/\d{4})\s+(\d{2}\/\d{2}\/\d{4})\s+R\$\s*([0-9.]+,\d{2})/iu', $texto, $resultado)) {
            $campos['dat_emissao'] = $resultado[1];
            $campos['num_nf'] = $resultado[2];
            $campos['referencia'] = $resultado[3];
            $campos['dat_vencimento'] = $resultado[4];
            $campos['val_total'] = $resultado[5];
        }

        if (!isset($campos['referencia']) && preg_match('/REFER[ÊE]NCIA\s*[:\-]?\s*([A-Z]{3}\/\d{2,4}|\d{2}\/\d{4})/iu', $texto, $resultado)) {
            $campos['referencia'] = $this->normalizarReferencia($resultado[1]);
        }

        if (!isset($campos['dat_vencimento']) && preg_match('/DATA\s+DE\s+VENCIMENTO\s*[:\-]?\s*(\d{2}\/\d{2}\/\d{4})/iu', $texto, $resultado)) {
            $campos['dat_vencimento'] = $resultado[1];
        }

        if (!isset($campos['val_total']) && preg_match('/VALOR\s+DO\s+DOCUMENTO\s*[:\-]?\s*R\$\s*([0-9.]+,\d{2})/iu', $texto, $resultado)) {
            $campos['val_total'] = $resultado[1];
        }

        return $campos;
    }

    private function extrairResumoPagamento(string $texto): array
    {
        $campos = [];

        if (preg_match('/\b([A-Z]{3}\/\d{2,4})\b\s+R\$\s*[*\s]*([0-9.]+,\d{2})\s+(\d{2}\/\d{2}\/\d{4})/iu', $texto, $resultado)) {
            $campos['referencia'] = $this->normalizarReferencia($resultado[1]);
            $campos['val_total'] = $resultado[2];
            $campos['dat_vencimento'] = $resultado[3];
        }

        if (!isset($campos['referencia']) && preg_match('/\b(0[1-9]|1[0-2])\/(\d{4})\b\s+NOTA\s+FISCAL/iu', $texto, $resultado)) {
            $campos['referencia'] = $resultado[1] . '/' . $resultado[2];
        }

        return $campos;
    }

    private function extrairDatasLeitura(string $texto): array
    {
        $campos = [];

        if (preg_match('/(?:Classifica[çc][ãa]o:.*?|[AB]\s*-\s*[AB][1-4].*?)\s+(\d{2}\/\d{2}\/\d{4})\s+(\d{2}\/\d{2}\/\d{4})\s+\d{1,3}\s+(\d{2}\/\d{2}\/\d{4})/ius', $texto, $resultado)) {
            $campos['dat_leitura_anterior'] = $resultado[1];
            $campos['dat_leitura_atual'] = $resultado[2];
            $campos['dta_leitura_prox'] = $resultado[3];
        }

        return $campos;
    }

    private function extrairPercentualTributo(string $texto, string $tributo_regex): ?string
    {
        if (preg_match('/\b' . $tributo_regex . '\b\s+[0-9.]+,\d{2}\s+([0-9.]+,\d{1,4})%?/iu', $texto, $resultado)) {
            return rtrim($resultado[1], '%');
        }

        return null;
    }

    private function normalizarReferencia(string $referencia): string
    {
        $referencia = strtoupper(trim($referencia));

        if (preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $referencia)) {
            return $referencia;
        }

        if (preg_match('/^(JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\/(\d{2,4})$/u', $referencia, $resultado)) {
            $ano = strlen($resultado[2]) === 2 ? '20' . $resultado[2] : $resultado[2];
            return $this->$resultado[1] . '/' . $ano;
        }

        return $referencia;
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[ \t]+/', ' ', $texto);
        $texto = preg_replace('/ *\n */', "\n", $texto);

        return trim($texto);
    }

    private function filtrarTextoFatura(string $texto): string
    {
        $texto = preg_replace('/(?:0,000)?DEMFP\s*/iu', ' ', $texto);
        $texto = $this->normalizarTexto($texto);

        if ($texto === '') return $texto;

        $linhas = explode("\n", $texto);
        $linhas_filtradas = [];
        $manter_proximas = 0;

        $padroes_descartar = [
            '/\bSEGUNDA\s+VIA\b/iu',
            '/\bP[ÁA]GINA\s+\d+\s*\/\s*\d+/iu',
            '/QRCode|QR\s*Code|Pague\s+via\s+PIX|PIX!/iu',
            '/d[ée]bito\s+autom[áa]tico|Cadastre-se\s+em\s+seu\s+banco/iu',
            '/\b0800\b|ENEL\s+DISTRIBUI[ÇC][ÃA]O.*0800/iu',
            '/Consulte\s+pela\s+Chave|dfe-portal|sefazvirtual|\/NF3e\/|consulta\b/iu',
            '/Protocolo\s+de\s+autoriza[çc][ãa]o/iu',
            '/Confira\s+aqui\s+o\s+DEC|\bDEC\b.*\bFEC\b/iu',
            '/N[ãa]o\s+constam\s+d[ée]bitos|Esta\s+declara[çc][ãa]o|quita\s+d[ée]bitos\s+anteriores|eventualmente\s+n[ãa]o\s+faturados|faturamentos\s+mensais/iu',
            '/ENCARGOS\s+POR\s+ATRASO|APROVEITE\s+OS\s+BENEF[ÍI]CIOS|MENSAGEM\s*:/iu',
            '/Regime\s+Especial|TATE\d+/iu',
        ];

        $padroes_manter = [
            '/N[úu]mero\s+UC|\bUC\b|Unidade\s+consumidora|N[ºo]\s+do\s+cliente/iu',
            '/PAGADOR|CLIENTE|CONSUMIDOR|DESTINAT[ÁA]RIO|CNPJ|CPF|INSC\.?\s*EST/iu',
            '/NOTA\s+FISCAL|\bNF\b|S[ÉE]RIE|Chave\s+de\s+acesso|N[ºo]\s+CONTROLE/iu',
            '/DATA\s+DE\s+EMISS[ÃA]O|REFER[ÊE]NCIA|DATA\s+DE\s+VENCIMENTO|VALOR\s+DO\s+DOCUMENTO/iu',
            '/Data\s+de\s+apresenta[çc][ãa]o|Leitura\s+Anterior|Leitura\s+Atual|Pr[óo]x(?:ima)?\s+Leitura/iu',
            '/\b[A-B]\s*-\s*[AB][1-4]\s*-\s*(VERDE|AZUL|CONVENCIONAL|BRANCA)\b|Classifica[çc][ãa]o:.*\b[AB][1-4]\b|THS_(?:VERDE|AZUL)/iu',
            '/SUBGRUPO|MODALIDADE\s*TARIF[ÁA]RIA|Bandeira\(s\)\s+tarif[áa]ria/iu',
            '/PIS\/PASEP|\bPIS\b|\bPASEP\b|COFINS|\bICMS\b/iu',
            '/Demanda\s+kW|Consumo\s+Faturado|N[ºo]\s+DIAS|Hora\s+Ponta|Hora\s+Fora\s+Ponta/iu',
            '/\b(?:JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\/\d{2}\b/iu',
            '/Demanda\s+-\s*KW|Demanda\s+de\s+Gera[çc][ãa]o|Demanda\s+Contratada/iu',
            '/Itens\s+de\s+Fatura|Unid\.|Quant\.|Pre[çc]o\s+unit|Valor\s+\(R\$\)|Tarifa\s+unit/iu',
            '/CONSUMO|DEMANDA|UFER|REATIV|ENCARGO|CIP|CONTRIB\.?\s+ILUM|BENEF[ÍI]CIO|DEDU[ÇC][ÃA]O|DIF\.?\s*(?:FATUR|DESC)|PARCELA\s+(?:TUSD|TE)|TUSD|TE\b|HOMOLOG/iu',
            '/Subtotal|TOTAL\b/iu',
            '/\b\d{2}\/\d{2}\/\d{4}\b|\b\d{2}\/\d{4}\b|R\$\s*\d/iu',
            '/(?:\d[\s.\-\/]*){44,}/u',
        ];

        foreach ($linhas as $linha) {
            $linha = trim($linha);

            if ($linha === '') continue;
            $linha = preg_replace('/^\d{6,}(?=N[ÚU]mero\s+UC)/iu', '', $linha);

            if (preg_match('/^[\d\s-]+$/u', $linha) && strlen($this->somenteDigitos($linha)) > 44) continue;

            $descartar = false;
            foreach ($padroes_descartar as $padrao) {
                if (preg_match($padrao, $linha)) {
                    $descartar = true;
                    break;
                }
            }

            if ($descartar) continue;

            $manter = $manter_proximas > 0;

            foreach ($padroes_manter as $padrao) {
                if (preg_match($padrao, $linha)) {
                    $manter = true;
                    break;
                }
            }

            if (!$manter) continue;

            $linha = $this->limparLinhaProduto($linha);
            $linhas_filtradas[] = $linha;

            if (preg_match('/^(?:DATA\s+DE\s+EMISS[ÃA]O|REFER[ÊE]NCIA|DATA\s+DE\s+VENCIMENTO|VALOR\s+DO\s+DOCUMENTO|NOTA\s+FISCAL|Chave\s+de\s+acesso|PAGADOR\s*\/\s*CPF)\s*:?\s*$/iu', $linha)) {
                $manter_proximas = max($manter_proximas, 2);
            } elseif ($manter_proximas > 0) {
                $manter_proximas--;
            }
        }

        return implode("\n", $linhas_filtradas);
    }

    private function limparLinhaProduto(string $linha): string
    {
        $descricoes_produto_regex = implode('|', [
            'DEMANDA\s+[ÚU]NICA',
            'DEMANDA\s+LIVRE',
            'DIF\.?\s*FATUR',
            'DIF\.?\s*DESC',
            'CONSUMO\s+ATIVO',
            'PARCELA\s+(?:TUSD|TE)',
            'DEMANDA\s+LEI',
            'ENCARGO\s+ESCASSEZ',
            'BENEF[ÍI]CIO\s+TARIF[ÁA]RIO',
            'CONTRIB\.?\s+ILUM',
            'CIP[-\s]',
        ]);

        if (preg_match('/((' . $descricoes_produto_regex . ').*)$/iu', $linha, $resultado)) {
            return trim($resultado[1]);
        }

        if (preg_match('/((UFER\s+).*)$/iu', $linha, $resultado)) {
            return trim($resultado[1]);
        }

        $inicio_produto = null;
        $descricoes_produto = [
            'DEMANDA\s+[ÚU]NICA',
            'DEMANDA\s+LIVRE',
            'DIF\.?\s*FATUR',
            'DIF\.?\s*DESC',
            'CONSUMO\s+ATIVO',
            'PARCELA\s+(?:TUSD|TE)',
            'UFER\s+',
            'DEMANDA\s+LEI',
            'ENCARGO\s+ESCASSEZ',
            'BENEF[ÍI]CIO\s+TARIF[ÁA]RIO',
            'CONTRIB\.?\s+ILUM',
            'CIP[-\s]',
        ];

        foreach ($descricoes_produto as $descricao) {
            if (preg_match('/' . $descricao . '/iu', $linha, $resultado, PREG_OFFSET_CAPTURE)) {
                $posicao = $resultado[0][1];
                if ($inicio_produto === null || $posicao < $inicio_produto) {
                    $inicio_produto = $posicao;
                }
            }
        }

        if ($inicio_produto === null || $inicio_produto === 0) return $linha;

        return trim(substr($linha, $inicio_produto));
    }

    private function extrairDadosTabulados(string $texto_filtrado): array
    {
        $dados = [];

        $produtos = $this->extrairProdutosTabulados($texto_filtrado);
        if ($produtos !== []) {
            $dados['produtos'] = $produtos;
        }

        $historico = $this->extrairHistoricoTabulado($texto_filtrado);
        if ($historico !== []) {
            $dados['historico'] = $historico;
        }

        return $dados;
    }

    private function extrairProdutosTabulados(string $texto): array
    {
        $linhas = explode("\n", $this->normalizarTexto($texto));
        $produtos = [];

        foreach ($linhas as $linha) {
            $linha = trim($linha);

            if (!$this->ehLinhaProduto($linha)) continue;

            $produto = $this->parsearLinhaProduto($linha);
            if ($produto !== null) $produtos[] = $produto;
        }

        return $produtos;
    }

    private function parsearLinhaProduto(string $linha): ?array
    {
        $tokens = preg_split('/\s+/', trim($linha));
        if (!$tokens || count($tokens) < 2) return null;

        $inicio_valores = null;
        $total_tokens = count($tokens);

        for ($indice = 0; $indice < $total_tokens; $indice++) {
            if (!$this->ehNumeroBrasileiro($tokens[$indice])) continue;

            $numeros_restantes = 0;
            for ($subindice = $indice; $subindice < $total_tokens; $subindice++) {
                if ($this->ehNumeroBrasileiro($tokens[$subindice])) {
                    $numeros_restantes++;
                }
            }

            if ($numeros_restantes >= 1) {
                $inicio_valores = $indice;
                break;
            }
        }

        if ($inicio_valores === null) return null;

        $unidade = null;
        $fim_descricao = $inicio_valores;

        if ($inicio_valores > 0 && $this->ehUnidadeProduto($tokens[$inicio_valores - 1])) {
            $unidade = strtoupper($tokens[$inicio_valores - 1]);
            $fim_descricao--;
        }

        $descricao = trim(implode(' ', array_slice($tokens, 0, $fim_descricao)));
        if ($descricao === '' || $this->ehLinhaNaoProduto($descricao)) {
            return null;
        }

        $numeros = [];
        for ($indice = $inicio_valores; $indice < $total_tokens; $indice++) {
            if ($this->ehNumeroBrasileiro($tokens[$indice])) {
                $numeros[] = $tokens[$indice];
            }
        }

        if ($numeros === []) return null;

        $produto = [
            'descricao' => $descricao,
            'unidade' => $unidade,
            'quantidade' => null,
            'preco' => null,
            'valor' => null,
        ];

        if ($unidade !== null || preg_match('/^(?:DIF\.?\s*(?:FATUR|DESC)|BENEF[ÍI]CIO)/iu', $descricao)) {
            $produto['quantidade'] = $numeros[0] ?? null;
            $produto['preco'] = $numeros[1] ?? null;
            $produto['valor'] = $numeros[2] ?? null;
        } else {
            $produto['valor'] = $numeros[0] ?? null;
        }

        return $produto['valor'] === null ? null : $produto;
    }

    private function extrairHistoricoTabulado(string $texto): array
    {
        $linhas = explode("\n", $this->normalizarTexto($texto));
        $historico = [];

        foreach ($linhas as $linha) {
            $linha = trim($linha);

            if (!preg_match('/^(JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\/(\d{2,4})\s+(.+)$/iu', $linha, $resultado)) {
                continue;
            }

            preg_match_all('/-?\d{1,3}(?:\.\d{3})*,\d+|-?\d+,\d+/u', $resultado[3], $valores);
            $numeros = $valores[0] ?? [];

            if (count($numeros) < 2) continue;

            $ano = strlen($resultado[2]) === 2 ? '20' . $resultado[2] : $resultado[2];
            $historico[] = [
                'descricao' => $this->$resultado[1] . '/' . $ano,
                'consumoFP' => $numeros[2] ?? null,
                'consumoP' => $numeros[1] ?? null,
                'demandaFP' => $numeros[0] ?? null,
                'demandaP' => null,
                'consumoRE' => $numeros[3] ?? null,
            ];
        }

        return $historico;
    }

    private function ehLinhaProduto(string $linha): bool
    {
        if ($linha === '' || $this->ehLinhaNaoProduto($linha)) {
            return false;
        }

        if (!preg_match('/-?\d{1,3}(?:\.\d{3})*,\d+|-?\d+,\d+/u', $linha)) {
            return false;
        }

        return (bool) preg_match('/\b(?:CONSUMO|DEMANDA|UFER|REATIV|ENCARGO|CIP|CONTRIB\.?\s+ILUM|BENEF[ÍI]CIO|DEDU[ÇC][ÃA]O|DIF\.?\s*(?:FATUR|DESC)|PARCELA\s+(?:TUSD|TE))\b/iu', $linha);
    }

    private function ehLinhaNaoProduto(string $linha): bool
    {
        return (bool) preg_match('/^\s*(?:PIS(?:\/PASEP)?|PASEP|COFINS|ICMS|BASE\s+DE\s+C[ÁA]LCULO|AL[ÍI]QUOTA|TRIBUTO|SUBTOTAL|TOTAL)\b/iu', $linha);
    }

    private function ehNumeroBrasileiro(string $valor): bool
    {
        return (bool) preg_match('/^-?\d{1,3}(?:\.\d{3})*,\d+$|^-?\d+,\d+$/u', $valor);
    }

    private function ehUnidadeProduto(string $valor): bool
    {
        return (bool) preg_match('/^(?:KW|KWH|KVARH|MWH)$/iu', $valor);
    }

    private function extrairChaveAcesso(string $texto): ?string
    {
        $janelas_prioritarias = [];

        if (preg_match_all('/Chave\s+de\s+acesso\s*:?\s*([^\n]*(?:\n[^\n]*){0,2})/iu', $texto, $resultados)) {
            $janelas_prioritarias = $resultados[1];
        }

        foreach (array_merge($janelas_prioritarias, [$texto]) as $janela) {
            foreach ($this->candidatasChaveAcesso($janela) as $candidata) {
                if ($this->chaveAcessoNf3eValida($candidata)) {
                    return $candidata;
                }
            }
        }

        foreach (array_merge($janelas_prioritarias, [$texto]) as $janela) {
            foreach ($this->candidatasChaveAcesso($janela) as $candidata) {
                if (substr($candidata, 20, 2) === '66') {
                    return $candidata;
                }
            }
        }

        return null;
    }

    private function candidatasChaveAcesso(string $texto): array
    {
        if (!preg_match_all('/[0-9][0-9\s.\-\/]{42,}[0-9]/u', $texto, $resultados)) {
            return [];
        }

        $candidatas = [];

        foreach ($resultados[0] as $trecho) {
            $digitos = $this->somenteDigitos($trecho);
            $tamanho = strlen($digitos);

            for ($indice = 0; $indice <= $tamanho - 44; $indice++) {
                $candidata = substr($digitos, $indice, 44);
                $candidatas[$candidata] = true;
            }
        }

        return array_keys($candidatas);
    }

    private function chaveAcessoNf3eValida(string $chave): bool
    {
        if (!preg_match('/^\d{44}$/', $chave)) return false;
        if (substr($chave, 20, 2) !== '66') return false;

        $base = substr($chave, 0, 43);

        return $this->calcularDigitoChaveAcesso($base) === (int) $chave[43];
    }

    private function calcularDigitoChaveAcesso(string $base): int
    {
        $soma = 0;
        $peso = 2;

        for ($indice = strlen($base) - 1; $indice >= 0; $indice--) {
            $soma += (int) $base[$indice] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }

    private function extrairClassificacao(string $texto): array
    {
        $modalidades = 'VERDE|AZUL|CONVENCIONAL|BRANCA';

        if (preg_match('/\b[AB]\s*-\s*(A[1-4]|B[1-4])\s*-\s*(' . $modalidades . ')\b/iu', $texto, $resultado)) {
            return [
                'cod_subgrupo' => strtoupper($resultado[1]),
                'codigo_modalidade' => strtoupper($resultado[2]),
            ];
        }

        if (preg_match('/Classifica[çc][ãa]o:\s*[AB]\s+([AB][1-4]).*?\b(?:THS_)?(' . $modalidades . ')\b/ius', $texto, $resultado)) {
            return [
                'cod_subgrupo' => strtoupper($resultado[1]),
                'codigo_modalidade' => strtoupper($resultado[2]),
            ];
        }

        $campos = [];

        $subgrupo = $this->buscarPrimeiro($texto, [
            '/SUBGRUPO\s*[:\-]?\s*(A[1-4]|B[1-4])\b/iu',
            '/\b(A[1-4]|B[1-4])\b/u',
        ]);

        if ($subgrupo !== null) {
            $campos['cod_subgrupo'] = strtoupper($subgrupo);
        }

        $modalidade = $this->buscarPrimeiro($texto, [
            '/MODALIDADE\s*(?:TARIF[ÁA]RIA)?\s*[:\-]?\s*(' . $modalidades . ')\b/iu',
            '/THS_(' . $modalidades . ')\b/iu',
        ]);

        if ($modalidade !== null) {
            $campos['codigo_modalidade'] = strtoupper($modalidade);
        }

        return $campos;
    }

    private function buscarPrimeiro(string $texto, array $padroes): ?string
    {
        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $texto, $resultado)) {
                return trim($resultado[1]);
            }
        }

        return null;
    }

    private function somenteDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor);
    }
}
