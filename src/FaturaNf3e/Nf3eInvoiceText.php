<?php

/**
 * Centraliza pequenas normalizações usadas antes da extração da NF3-e.
 *
 * Esses helpers reduzem variações comuns para deixar regexes e
 * comparações numéricas mais previsíveis nas outras classes do módulo.
 */
class Nf3eInvoiceText
{

    // Padroniza quebras de linha e espaços do texto bruto da fatura.
    public static function fNormalizar(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[ \t]+/', ' ', $texto);
        $texto = preg_replace('/ *\n */', "\n", $texto);

        return trim($texto);
    }

    /**
     * Remove qualquer caractere que não seja dígito.
     *
     * Usado para comparar CNPJ, chave de acesso e outros códigos numéricos que
     * podem vir com máscara, espaços ou ruído do OCR.
     */
    public static function FsomenteDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor);
    }

    /**
     * Normaliza a referência para comparação e saída consistente.
     *
     * Atualmente preserva referências no formato MM/AAAA e apenas aplica
     * maiúsculas/trim nos demais formatos reconhecidos pelo texto da fatura.
     */
    public static function fNormalizarReferencia(string $referencia): string
    {
        $referencia = strtoupper(trim($referencia));

        if (preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $referencia)) {
            return $referencia;
        }

        return $referencia;
    }
}
