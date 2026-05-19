<?php

class OllamaClient
{
    private $url_api;
    private $modelo;
    private $tempo_conexao_segundos;
    private $num_thread;

    public function __construct(
        string $url_api = 'http://localhost:11434/api/generate',
        string $modelo = 'qwen2.5:7b',
        int $tempo_conexao_segundos = 10,
        int $num_thread = 6
    ) {
        $this->url_api = $url_api;
        $this->modelo = $modelo;
        $this->tempo_conexao_segundos = $tempo_conexao_segundos;
        $this->num_thread = $num_thread;
    }

    public function urlApi(): string
    {
        return $this->url_api;
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    public function fCalculaNumCtx(string $prompt): int
    {
        $tokens_estimados = (int) ceil(strlen($prompt) / 3);

        if ($tokens_estimados <= 2500) return 4096;
        if ($tokens_estimados <= 6000) return 8192;

        return 12288;
    }

    public function gerar(
        string $prompt,
        int $tempo_requisicao_segundos,
        int $num_predict,
        int $num_ctx,
        string $id_debug,
        bool $debug,
        float $inicio_processamento,
        string $rotulo = 'IA: chamada cURL',
        bool $falhar_silenciosamente = false
    ): ?string {
        $inicio_payload = microtime(true);
        $payload = [
            'model' => $this->modelo,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
            'keep_alive' => '30m',
            'options' => [
                'temperature' => 0,
                'num_ctx' => $num_ctx,
                'num_predict' => $num_predict,
                'num_thread' => $this->num_thread,
            ],
        ];

        if ($debug) {
            registrarDebug('debug', $rotulo . ': payload montado', [
                'run_id' => $id_debug,
                'payload_model' => $payload['model'],
                'payload_stream' => $payload['stream'],
                'payload_format' => $payload['format'],
                'payload_options' => $payload['options'],
                'payload_prompt_length' => strlen($payload['prompt']),
                'connect_timeout_seconds' => $this->tempo_conexao_segundos,
                'request_timeout_seconds' => $tempo_requisicao_segundos,
                'step_duration_seconds' => $this->segundosDesde($inicio_payload),
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
        curl_setopt($curl, CURLOPT_TIMEOUT, $tempo_requisicao_segundos);
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);

        if ($debug) {
            registrarDebug('info', $rotulo . ': iniciada', [
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
            registrarDebug($codigo_erro_curl ? 'error' : 'info', $rotulo . ': finalizada', [
                'run_id' => $id_debug,
                'curl_errno' => $codigo_erro_curl,
                'curl_error' => $erro_curl,
                'http_code' => $codigo_http,
                'content_type' => $tipo_conteudo,
                'curl_total_time_seconds' => $tempo_total,
                'step_duration_seconds' => $this->segundosDesde($inicio_chamada),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
                'raw_response' => resumoDebug($resposta === false ? '' : $resposta, 2000),
            ]);
        }

        if ($resposta === false) {
            if ($falhar_silenciosamente) return null;

            if ($codigo_erro_curl === CURLE_OPERATION_TIMEDOUT) {
                throw new Exception(
                    "Erro ao chamar Ollama: a geração excedeu {$tempo_requisicao_segundos}s sem resposta. " .
                        "O modelo {$this->modelo} pode estar lento para este prompt; tente novamente, use um modelo menor ou aumente o timeout."
                );
            }

            throw new Exception("Erro ao chamar Ollama: " . ($erro_curl ?: 'erro desconhecido'));
        }

        if ($codigo_http < 200 || $codigo_http >= 300) {
            if ($falhar_silenciosamente) return null;
            throw new Exception("Erro ao chamar Ollama: HTTP {$codigo_http} - " . substr($resposta, 0, 500));
        }

        $inicio_decode = microtime(true);
        $dados_resposta = json_decode($resposta, true);
        $erro_json = json_last_error_msg();

        if ($debug) {
            registrarDebug(json_last_error() === JSON_ERROR_NONE ? 'debug' : 'error', $rotulo . ': resposta HTTP decodificada', [
                'run_id' => $id_debug,
                'json_error' => $erro_json,
                'decoded_keys' => is_array($dados_resposta) ? array_keys($dados_resposta) : null,
                'step_duration_seconds' => $this->segundosDesde($inicio_decode),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        if (!is_array($dados_resposta)) {
            if ($falhar_silenciosamente) return null;
            throw new Exception("Ollama retornou uma resposta HTTP sem JSON válido.");
        }

        return $dados_resposta['response'] ?? '';
    }

    private function segundosDesde(float $inicio): float
    {
        return round(microtime(true) - $inicio, 4);
    }
}
