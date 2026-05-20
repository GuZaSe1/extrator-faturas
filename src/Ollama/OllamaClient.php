<?php

class OllamaClient
{
    private $url_api;
    private $modelo;
    private $timeout_conexao;
    private $num_thread;

    public function __construct(
        string $url_api = 'http://localhost:11434/api/generate',
        string $modelo = 'qwen2.5:7b',
        int $timeout_conexao = 10,
        int $num_thread = 6
    ) {
        $this->url_api = $url_api;
        $this->modelo = $modelo;
        $this->timeout_conexao = $timeout_conexao;
        $this->num_thread = $num_thread;
    }

    public function fCalculaNumCtx(string $prompt): int
    {
        $tokens_estimados = (int) ceil(strlen($prompt) / 3);

        if ($tokens_estimados <= 2500) return 4096;
        if ($tokens_estimados <= 6000) return 8192;

        return 12288;
    }

    public function fGeraRespostaIa(
        string $prompt,
        int $timeout_requisicao,
        int $num_predict,
        int $num_ctx,
        string $id_debug,
        bool $debug,
        string $rotulo = 'IA: chamada cURL',
        bool $falhar_silenciosamente = false
    ): ?string {
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

        $curl = curl_init($this->url_api);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->timeout_conexao);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout_requisicao);
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);

        $resposta = curl_exec($curl);
        $erro_curl = curl_error($curl);
        $codigo_erro_curl = curl_errno($curl);
        $codigo_http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($resposta === false) {
            if ($falhar_silenciosamente) return null;

            if ($codigo_erro_curl === CURLE_OPERATION_TIMEDOUT) {
                throw new Exception(
                    "Erro ao chamar Ollama: a geração excedeu {$timeout_requisicao}s sem resposta. " .
                        "O modelo {$this->modelo} pode estar lento para este prompt; tente novamente, use um modelo menor ou aumente o timeout."
                );
            }

            throw new Exception("Erro ao chamar Ollama: " . ($erro_curl ?: 'erro desconhecido'));
        }

        if ($codigo_http < 200 || $codigo_http >= 300) {
            if ($falhar_silenciosamente) return null;
            throw new Exception("Erro ao chamar Ollama: HTTP {$codigo_http} - " . substr($resposta, 0, 500));
        }

        $dados_resposta = json_decode($resposta, true);

        if (!is_array($dados_resposta)) {
            if ($falhar_silenciosamente) return null;
            throw new Exception("Ollama retornou uma resposta HTTP sem JSON válido.");
        }

        return $dados_resposta['response'] ?? '';
    }
}
