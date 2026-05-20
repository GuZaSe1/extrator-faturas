<?php

/**
 * Logger simples para rastrear o processamento de faturas
 *
 * Ele grava eventos em logs/app.log e oferece um helper para resumir textos
 * grandes antes de colocá-los no contexto do log
 */
class DebugLogger
{
    private $diretorio_logs;

    public function __construct(string $diretorio_logs)
    {
        $this->diretorio_logs = $diretorio_logs;
    }

    // Registra um evento de debug com nível, mensagem e contexto opcional
    public function fRegistraEvento(string $nivel, string $mensagem, array $contexto = []): void
    {
        // Garante que o diretório exista antes de tentar escrever o arquivo.
        if (!is_dir($this->diretorio_logs)) {
            mkdir($this->diretorio_logs, 0775, true);
        }

        $linha = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($nivel),
            $mensagem,
            $contexto ? json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );

        file_put_contents($this->diretorio_logs . '/app.log', $linha, FILE_APPEND | LOCK_EX);
    }

    // Resume textos longos para logs sem perder tamanho total e indicação de corte
    public static function fResumeTextoParaDebug(?string $valor, int $limite = 8192): array
    {
        $valor = $valor ?? '';
        $tamanho = strlen($valor);

        return [
            'tamanho' => $tamanho,
            'trecho' => substr($valor, 0, $limite),
            'truncado' => $tamanho > $limite,
        ];
    }
}
