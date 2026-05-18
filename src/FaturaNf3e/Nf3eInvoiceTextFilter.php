<?php

require_once __DIR__ . '/Nf3eInvoiceText.php';

/**
 * Reduz o texto bruto da NF3e aos trechos úteis para extração e IA
 *
 * O filtro remove mensagens institucionais, QR Code, dados de consulta e outros
 * ruídos presentes, preservando campos fiscais, cobrança, classificação,
 * tributos, produtos e histórico de consumo
 */
class Nf3eInvoiceTextFilter
{

    // Filtra linhas relevantes mantendo contexto imediato de rótulos importantes
    public function fFiltrar(string $texto): string
    {
        // Remove um fragmento recorrente de OCR que costuma grudar em descrições de demanda
        $texto = preg_replace('/(?:0,000)?DEMFP\s*/iu', ' ', $texto);
        $texto = Nf3eInvoiceText::fNormalizar($texto);

        if ($texto === '') return $texto;

        $linhas = explode("\n", $texto);
        $linhas_filtradas = [];
        $manter_proximas = 0;

        // Blocos informativos que aumentam o prompt, mas não ajudam a preencher os campos
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

        // Padrões que identificam linhas com dados estruturais da fatura
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

            // Descarta sequências numéricas longas sem rótulo, comuns em códigos de barras/linhas digitáveis
            if (preg_match('/^[\d\s-]+$/u', $linha) && strlen(Nf3eInvoiceText::FsomenteDigitos($linha)) > 44) continue;

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

            $linha = $this->fLimparLinhaProduto($linha);
            $linhas_filtradas[] = $linha;

            // Alguns rótulos vêm sozinhos; nesses casos, as próximas linhas carregam os valores
            if (preg_match('/^(?:DATA\s+DE\s+EMISS[ÃA]O|REFER[ÊE]NCIA|DATA\s+DE\s+VENCIMENTO|VALOR\s+DO\s+DOCUMENTO|NOTA\s+FISCAL|Chave\s+de\s+acesso|PAGADOR\s*\/\s*CPF)\s*:?\s*$/iu', $linha)) {
                $manter_proximas = max($manter_proximas, 2);
            } elseif ($manter_proximas > 0) $manter_proximas--;
        }

        return implode("\n", $linhas_filtradas);
    }


    // Remove prefixos acidentais antes da descrição real de um item faturado
    private function fLimparLinhaProduto(string $linha): string
    {
        // Primeiro tenta capturar a descrição e todo o restante da linha em uma única regex
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
        // Fallback: encontra a descrição mais à esquerda e corta qualquer lixo anterior
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
}
