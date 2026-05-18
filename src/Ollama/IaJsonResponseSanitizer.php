<?php

class IaJsonResponseSanitizer
{
    public function limpar(string $json_bruto): string
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
}
