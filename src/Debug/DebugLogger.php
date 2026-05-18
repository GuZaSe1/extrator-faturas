<?php

class DebugLogger
{
    private $diretorio_logs;

    public function __construct(string $diretorio_logs)
    {
        $this->diretorio_logs = $diretorio_logs;
    }

    public function registrar(string $nivel, string $mensagem, array $contexto = []): void
    {
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

    public static function resumir(?string $valor, int $limite = 8192): array
    {
        $valor = $valor ?? '';
        $tamanho = strlen($valor);

        return [
            'length' => $tamanho,
            'excerpt' => substr($valor, 0, $limite),
            'truncated' => $tamanho > $limite,
        ];
    }
}
