<?php

/**
 * Normaliza o resultado final da extração de NF3e
 *
 * Esta classe garante que a resposta tenha sempre as chaves esperadas pela API,
 * limpa itens inválidos de produtos/histórico e preserva campos extras que possam
 * ter vindo da IA ou de extratores complementares
 */
class Nf3eResultNormalizer
{
    // Contrato mínimo de saída: campos escalares começam nulos e listas começam vazias
    public const CAMPOS_OBRIGATORIOS = [
        'chave_acesso' => null,
        'num_cnpj_emit' => null,
        'num_cnpj_dest' => null,
        'num_nf' => null,
        'referencia' => null,
        'cod_unidade_consumo' => null,
        'dat_emissao' => null,
        'cod_subgrupo' => null,
        'codigo_modalidade' => null,
        'val_total' => null,
        'dat_leitura_anterior' => null,
        'dat_leitura_atual' => null,
        'dta_leitura_prox' => null,
        'dat_vencimento' => null,
        'demanda_contratada_fp' => null,
        'demanda_contratada_p' => null,
        'pct_cofins' => null,
        'pct_pis' => null,
        'produtos' => [],
        'historico' => [],
    ];

    // Converte chaves com hífen para underline
    public function fNormalizarChaves(array $resultado): array
    {
        $resultado_normalizado = [];

        foreach ($resultado as $chave => $valor) {
            $resultado_normalizado[str_replace('-', '_', (string) $chave)] = $valor;
        }

        return $resultado_normalizado;
    }

    // Monta a resposta final com campos obrigatórios ordenados e listas saneadas
    public function fNormalizarResultadoFinal(array $resultado): array
    {
        $resultado['produtos'] = $this->fNormalizarProdutos($resultado['produtos'] ?? []);
        $resultado['historico'] = $this->fNormalizarHistorico($resultado['historico'] ?? []);

        // Primeiro aplica o contrato fixo para manter ordem e valores padrão
        $ordenado = [];
        foreach (self::CAMPOS_OBRIGATORIOS as $campo => $padrao) {
            $ordenado[$campo] = array_key_exists($campo, $resultado) ? $resultado[$campo] : $padrao;
        }

        // Depois mantém campos adicionais sem misturá-los ao bloco obrigatório
        foreach ($resultado as $campo => $valor) {
            if (!array_key_exists($campo, $ordenado)) {
                $ordenado[$campo] = $valor;
            }
        }

        return $ordenado;
    }

    // Normaliza os itens de fatura e descarta linhas que não representam produto
    private function fNormalizarProdutos($produtos): array
    {
        if (!is_array($produtos)) return [];

        $normalizados = [];

        foreach ($produtos as $produto) {
            if (!is_array($produto)) continue;

            $descricao = trim((string) ($produto['descricao'] ?? ''));
            if ($descricao === '' || $this->ehLinhaNaoProduto($descricao)) continue;

            $normalizados[] = [
                'descricao' => $descricao,
                'unidade' => $this->fNormalizarValorVazio($produto['unidade'] ?? null, true),
                'quantidade' => $this->fNormalizarValorVazio($produto['quantidade'] ?? null),
                'preco' => $this->fNormalizarValorVazio($produto['preco'] ?? null),
                'valor' => $this->fNormalizarValorVazio($produto['valor'] ?? null),
            ];
        }

        return $normalizados;
    }

    // Normaliza o histórico de consumo mantendo apenas registros com descrição
    private function fNormalizarHistorico($historico): array
    {
        if (!is_array($historico)) return [];

        $normalizados = [];

        foreach ($historico as $item) {
            if (!is_array($item)) continue;

            $descricao = trim((string) ($item['descricao'] ?? ''));
            if ($descricao === '') continue;

            $normalizados[] = [
                'descricao' => $descricao,
                'consumoFP' => $this->fNormalizarValorVazio($item['consumoFP'] ?? null),
                'consumoP' => $this->fNormalizarValorVazio($item['consumoP'] ?? null),
                'demandaFP' => $this->fNormalizarValorVazio($item['demandaFP'] ?? null),
                'demandaP' => $this->fNormalizarValorVazio($item['demandaP'] ?? null),
                'consumoRE' => $this->fNormalizarValorVazio($item['consumoRE'] ?? null),
            ];
        }

        return $normalizados;
    }

    // Padroniza valores vazios como null e aplica maiúsculas quando o campo exigir
    private function fNormalizarValorVazio($valor, bool $maiusculo = false)
    {
        if ($valor === null) return null;

        $valor = trim((string) $valor);
        if ($valor === '') return null;

        return $maiusculo ? strtoupper($valor) : $valor;
    }

    // Identifica linhas de tributos/totais que podem aparecer junto dos produtos
    private function ehLinhaNaoProduto(string $linha): bool
    {
        return (bool) preg_match('/^\s*(?:PIS(?:\/PASEP)?|PASEP|COFINS|ICMS|BASE\s+DE\s+C[ÁA]LCULO|AL[ÍI]QUOTA|TRIBUTO|SUBTOTAL|TOTAL)\b/iu', $linha);
    }
}
