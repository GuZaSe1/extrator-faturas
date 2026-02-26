<?php

class processador_ia
{
    private $api_url = "http://localhost:11434/api/generate";
    private $model = "qwen2.5:7b";
    private $promptPath = __DIR__ . "/prompts/fatura_nf3e.txt";

    public function processInvoiceText(string $text)
    {
        // 1. Carrega o template do arquivo de texto
        if (!file_exists($this->promptPath)) {
            throw new Exception("Template de prompt não encontrado em: " . $this->promptPath);
        }

        $template = file_get_contents($this->promptPath);

        // 2. Substitui o placeholder pelo texto real do PDF
        $fullPrompt = str_replace("{{TEXTO_PDF}}", $text, $template);

        // 3. Monta o payload para o Ollama
        $payload = [
            "model"  => $this->model,
            "prompt" => $fullPrompt,
            "stream" => false,
            "format" => "json",
            "options" => [
                "temperature" => 0.1 // Mantém a IA focada e menos criativa
            ]
        ];

        $ch = curl_init($this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $rawJson = $data['response'] ?? '';

        // Limpeza de segurança para as barras que a IA costuma adicionar
        $cleanJson = str_replace('\/', '/', $rawJson);

        return json_decode($cleanJson, true);
    }
}
