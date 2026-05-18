<?php

require_once __DIR__ . '/Nf3eInvoiceText.php';

/**
 * Extrai dados tabulados da NF3e a partir do texto já filtrado
 *
 * Esta classe procura principalmente duas estruturas: itens da fatura e histórico
 * de consumo/demanda. Ela usa heurísticas simples porque o texto vem de PDF/OCR e
 * nem sempre preserva colunas de tabela com alinhamento confiável
 */
class Nf3eTableExtractor
{
    // Agrega as extrações tabulares disponíveis sem criar chaves vazias
    public function fExtrair(string $texto_filtrado): array
    {
        $dados = [];

        $produtos = $this->fExtrairProdutos($texto_filtrado);
        if ($produtos !== []) $dados['produtos'] = $produtos;

        $historico = $this->fExtrairHistorico($texto_filtrado);
        if ($historico !== []) $dados['historico'] = $historico;

        return $dados;
    }

    // Varre as linhas do texto e mantém apenas as que parecem itens faturados
    private function fExtrairProdutos(string $texto): array
    {
        $linhas = explode("\n", Nf3eInvoiceText::fNormalizar($texto));
        $produtos = [];

        foreach ($linhas as $linha) {
            $linha = trim($linha);

            if (!$this->ehLinhaProduto($linha)) continue;

            $produto = $this->fParsearLinhaProduto($linha);
            if ($produto !== null) $produtos[] = $produto;
        }

        return $produtos;
    }

    // Divide uma linha de item em descrição, unidade, quantidade, preço e valor
    private function fParsearLinhaProduto(string $linha): ?array
    {
        $tokens = preg_split('/\s+/', trim($linha));
        if (!$tokens || count($tokens) < 2) return null;

        $inicio_valores = null;
        $total_tokens = count($tokens);

        // A descrição fica antes do primeiro número monetário/decimal relevante
        for ($indice = 0; $indice < $total_tokens; $indice++) {
            if (!$this->ehNumeroBrasileiro($tokens[$indice])) continue;

            $numeros_restantes = 0;
            for ($subindice = $indice; $subindice < $total_tokens; $subindice++) {
                if ($this->ehNumeroBrasileiro($tokens[$subindice])) $numeros_restantes++;
            }

            if ($numeros_restantes >= 1) {
                $inicio_valores = $indice;
                break;
            }
        }

        if ($inicio_valores === null) return null;

        $unidade = null;
        $fim_descricao = $inicio_valores;

        // Quando a unidade vem imediatamente antes dos valores, ela não faz parte da descrição
        if ($inicio_valores > 0 && $this->ehUnidadeProduto($tokens[$inicio_valores - 1])) {
            $unidade = strtoupper($tokens[$inicio_valores - 1]);
            $fim_descricao--;
        }

        $descricao = trim(implode(' ', array_slice($tokens, 0, $fim_descricao)));
        if ($descricao === '' || $this->ehLinhaNaoProduto($descricao)) return null;

        $numeros = [];
        for ($indice = $inicio_valores; $indice < $total_tokens; $indice++) {
            if ($this->ehNumeroBrasileiro($tokens[$indice])) $numeros[] = $tokens[$indice];
        }

        if ($numeros === []) return null;

        $produto = [
            'descricao' => $descricao,
            'unidade' => $unidade,
            'quantidade' => null,
            'preco' => null,
            'valor' => null,
        ];

        // Linhas com unidade têm colunas completas; outras linhas geralmente trazem só o valor
        if ($unidade !== null || preg_match('/^(?:DIF\.?\s*(?:FATUR|DESC)|BENEF[ÍI]CIO)/iu', $descricao)) {
            $produto['quantidade'] = $numeros[0] ?? null;
            $produto['preco'] = $numeros[1] ?? null;
            $produto['valor'] = $numeros[2] ?? null;
        } else {
            $produto['valor'] = $numeros[0] ?? null;
        }

        return $produto['valor'] === null ? null : $produto;
    }

    // Extrai linhas de histórico no formato MES/ANO seguido por colunas numéricas
    private function fExtrairHistorico(string $texto): array
    {
        $linhas = explode("\n", Nf3eInvoiceText::fNormalizar($texto));
        $historico = [];

        foreach ($linhas as $linha) {
            $linha = trim($linha);

            if (!preg_match('/^(JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\/(\d{2,4})\s+(.+)$/iu', $linha, $resultado)) {
                continue;
            }

            preg_match_all('/-?\d{1,3}(?:\.\d{3})*,\d+|-?\d+,\d+/u', $resultado[3], $valores);
            $numeros = $valores[0] ?? [];

            if (count($numeros) < 2) continue;

            // A ordem dos números segue o layout tabular usual da seção de histórico
            $historico[] = [
                'descricao' => strtoupper($resultado[1]) . '/' . $resultado[2],
                'consumoFP' => $numeros[2] ?? null,
                'consumoP' => $numeros[1] ?? null,
                'demandaFP' => $numeros[0] ?? null,
                'demandaP' => null,
                'consumoRE' => $numeros[3] ?? null,
            ];
        }

        return $historico;
    }

    // Confirma se a linha parece um item de fatura com descrição e valor
    private function ehLinhaProduto(string $linha): bool
    {
        if ($linha === '' || $this->ehLinhaNaoProduto($linha)) return false;
        if (!preg_match('/-?\d{1,3}(?:\.\d{3})*,\d+|-?\d+,\d+/u', $linha)) return false;

        return (bool) preg_match('/\b(?:CONSUMO|DEMANDA|UFER|REATIV|ENCARGO|CIP|CONTRIB\.?\s+ILUM|BENEF[ÍI]CIO|DEDU[ÇC][ÃA]O|DIF\.?\s*(?:FATUR|DESC)|PARCELA\s+(?:TUSD|TE))\b/iu', $linha);
    }

    // Identifica totais e linhas tributárias que não devem entrar como produto
    private function ehLinhaNaoProduto(string $linha): bool
    {
        return (bool) preg_match('/^\s*(?:PIS(?:\/PASEP)?|PASEP|COFINS|ICMS|BASE\s+DE\s+C[ÁA]LCULO|AL[ÍI]QUOTA|TRIBUTO|SUBTOTAL|TOTAL)\b/iu', $linha);
    }

    // Reconhece números no formato brasileiro usado nas tabelas da fatura
    private function ehNumeroBrasileiro(string $valor): bool
    {
        return (bool) preg_match('/^-?\d{1,3}(?:\.\d{3})*,\d+$|^-?\d+,\d+$/u', $valor);
    }

    // Reconhece unidades que indicam consumo ou demanda faturada
    private function ehUnidadeProduto(string $valor): bool
    {
        return (bool) preg_match('/^(?:KW|KWH|KVARH|MWH)$/iu', $valor);
    }
}
