<?php

/**
 * Prepara respostas da IA para decodificação com json_decode
 *
 * A IA pode devolver JSON puro, JSON dentro de bloco markdown ou texto extra ao
 * redor do objeto. Esta classe remove esses envoltórios comuns antes do parse
 */
class IaJsonResponseSanitizer
{
    // Extrai o JSON aproveitável de uma resposta textual da IA
    public function fExtraiJsonDaRespostaIa(string $json_bruto): string
    {
        // Normaliza barras escapadas e remove cercas markdown como ```json
        $json = trim(str_replace('\/', '/', $json_bruto));
        $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
        $json = preg_replace('/\s*```$/', '', $json);

        // Se o conteúdo já é JSON válido, não tenta cortar nada
        if (json_decode($json, true) !== null && json_last_error() === JSON_ERROR_NONE) return $json;

        $inicio = strpos($json, '{');
        $fim = strrpos($json, '}');

        // Fallback para respostas com texto antes/depois do objeto JSON
        if ($inicio !== false && $fim !== false && $fim > $inicio) {
            return substr($json, $inicio, $fim - $inicio + 1);
        }

        return $json;
    }
}
