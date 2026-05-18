<?php

require_once __DIR__ . '/Nf3eInvoiceText.php';

/**
 * Extrai campos recorrentes da NF3e por regras determinísticas
 *
 * A classe complementa a IA: usa regexes conservadoras para campos que aparecem
 * em posições previsíveis e valida a chave de acesso antes de derivar dados dela
 */
class Nf3eDeterministicExtractor
{
    /**
     * Orquestra as extrações gerais e os refinamentos por layout
     *
     * A chave de acesso é avaliada primeiro porque, quando válida, permite derivar
     * CNPJ do emitente, série e número da NF diretamente da estrutura oficial. Em
     * seguida, padrões mais específicos do texto podem confirmar ou sobrescrever campos
     */
    public function fExtrair(string $texto): array
    {
        $campos = [];
        $texto_normalizado = Nf3eInvoiceText::fNormalizar($texto);

        $chave_acesso = $this->fExtrairChaveAcesso($texto);
        if ($chave_acesso !== null) {
            $campos['chave_acesso'] = $chave_acesso;

            if (strlen($chave_acesso) === 44) {
                $campos['num_cnpj_emit'] = substr($chave_acesso, 6, 14);
                $campos['serie'] = substr($chave_acesso, 22, 3);
                $campos['num_nf'] = substr($chave_acesso, 25, 9);
            }
        }

        $nota_fiscal = $this->fBuscarPrimeiro($texto_normalizado, [
            '/NOTA\s+FISCAL\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})/iu',
            '/\bNF\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})/iu',
        ]);

        if ($nota_fiscal !== null) {
            $campos['num_nf'] = $nota_fiscal;
        }

        $serie = $this->fBuscarPrimeiro($texto_normalizado, [
            '/S[ÉE]RIE\s*[-:]?\s*(\d{1,3})/iu',
        ]);

        if ($serie !== null) {
            $campos['serie'] = str_pad($serie, 3, '0', STR_PAD_LEFT);
        }

        $campos = array_merge($campos, $this->fExtrairCabecalhoFatura($texto_normalizado));
        $campos = array_merge($campos, $this->fExtrairResumoPagamento($texto_normalizado));

        // Evita confundir CNPJ do destinatário com o do emitente já derivado da chave
        $cnpj_destinatario = $this->fBuscarPrimeiro($texto_normalizado, [
            '/(?:PAGADOR\s*\/\s*CPF:.*?|PAGADOR:.*?|CLIENTE:.*?|CONSUMIDOR:.*?|DESTINAT[ÁA]RIO:.*?)CNPJ(?:\/CPF)?\s*[:\-]?\s*([0-9*.]{2}\.[0-9*.]{3}\.[0-9*.]{3}\/[0-9*.]{4}-[0-9*.]{2})/ius',
            '/CNPJ(?:\/CPF)?\s*[:\-]?\s*([0-9*.]{2}\.[0-9*.]{3}\.[0-9*.]{3}\/[0-9*.]{4}-[0-9*.]{2})/iu',
            '/CNPJ(?:\/CPF)?\s*[:\-]?\s*([0-9*]{14})/iu',
        ]);

        if ($cnpj_destinatario !== null && (!isset($campos['num_cnpj_emit']) || Nf3eInvoiceText::FsomenteDigitos($cnpj_destinatario) !== $campos['num_cnpj_emit'])) {
            $campos['num_cnpj_dest'] = $cnpj_destinatario;
        }

        $unidade_consumo = $this->fBuscarPrimeiro($texto_normalizado, [
            '/N[ÚU]MERO\s+UC[^\n]*(?:\n[^\n]*){0,4}\n\s*(\d{4,20})\s*\/\s*\d{4,20}/iu',
            '/N[ÚU]MERO\s+UC\s+(\d{4,20})(?:\s*\/\s*\d{4,20})?/iu',
            '/\bUC\s*[:\-]?\s*(\d{4,20})(?:\s*\/\s*\d{4,20})?/iu',
            '/UNIDADE\s+CONSUMIDORA\s*[:\-]?\s*(\d{4,20})/iu',
            '/(?:^|\n)\s*(\d{4,20})\s*\/\s*\d{4,20}\s+R\$\s*[*.0-9]*,\d{2}/u',
        ]);

        if ($unidade_consumo !== null) {
            $campos['cod_unidade_consumo'] = $unidade_consumo;
        }

        $campos = array_merge($campos, $this->fExtrairDatasLeitura($texto_normalizado));

        $pct_pis = $this->fExtrairPercentualTributo($texto_normalizado, '(?:PIS(?:\/PASEP)?|PASEP)');
        if ($pct_pis !== null) {
            $campos['pct_pis'] = $pct_pis;
        }

        $pct_cofins = $this->fExtrairPercentualTributo($texto_normalizado, 'COFINS');
        if ($pct_cofins !== null) {
            $campos['pct_cofins'] = $pct_cofins;
        }

        return array_merge($campos, $this->fExtrairClassificacao($texto_normalizado));
    }

    /**
     * Lê campos do cabeçalho da fatura, cobrindo layouts em linha única e layouts
     * onde os rótulos ficam empilhados acima dos valores
     */
    private function fExtrairCabecalhoFatura(string $texto): array
    {
        $campos = [];

        if (preg_match('/NOTA\s+FISCAL\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})\s*-\s*S[ÉE]RIE\s*(\d{1,3})\s*\/\s*DATA\s+DE\s+EMISS[ÃA]O\s*:\s*(\d{2}\/\d{2}\/\d{4})/iu', $texto, $resultado)) {
            $campos['num_nf'] = $resultado[1];
            $campos['serie'] = str_pad($resultado[2], 3, '0', STR_PAD_LEFT);
            $campos['dat_emissao'] = $resultado[3];
        }

        // Algumas distribuidoras imprimem uma linha só de rótulos e a linha seguinte só de valores
        if (preg_match('/DATA\s+DE\s+EMISS[ÃA]O:\s*NOTA\s+FISCAL:\s*REFER[ÊE]NCIA:\s*DATA\s+DE\s+VENCIMENTO:\s*VALOR\s+DO\s+DOCUMENTO:\s*\n\s*(\d{2}\/\d{2}\/\d{4})\s+(\d{6,12})\s+(\d{2}\/\d{4})\s+(\d{2}\/\d{2}\/\d{4})\s+R\$\s*([0-9.]+,\d{2})/iu', $texto, $resultado)) {
            $campos['dat_emissao'] = $resultado[1];
            $campos['num_nf'] = $resultado[2];
            $campos['referencia'] = $resultado[3];
            $campos['dat_vencimento'] = $resultado[4];
            $campos['val_total'] = $resultado[5];
        }

        if (!isset($campos['referencia']) && preg_match('/REFER[ÊE]NCIA\s*[:\-]?\s*([A-Z]{3}\/\d{2,4}|\d{2}\/\d{4})/iu', $texto, $resultado)) {
            $campos['referencia'] = Nf3eInvoiceText::fNormalizarReferencia($resultado[1]);
        }

        if (!isset($campos['dat_vencimento']) && preg_match('/DATA\s+DE\s+VENCIMENTO\s*[:\-]?\s*(\d{2}\/\d{2}\/\d{4})/iu', $texto, $resultado)) {
            $campos['dat_vencimento'] = $resultado[1];
        }

        if (!isset($campos['val_total']) && preg_match('/VALOR\s+DO\s+DOCUMENTO\s*[:\-]?\s*R\$\s*([0-9.]+,\d{2})/iu', $texto, $resultado)) {
            $campos['val_total'] = $resultado[1];
        }

        return $campos;
    }

    /**
     * Captura o resumo de pagamento quando referência, valor e vencimento aparecem
     * juntos em uma faixa de cobrança separada do cabeçalho principal
     */
    private function fExtrairResumoPagamento(string $texto): array
    {
        $campos = [];

        if (preg_match('/\b([A-Z]{3}\/\d{2,4})\b\s+R\$\s*[*\s]*([0-9.]+,\d{2})\s+(\d{2}\/\d{2}\/\d{4})/iu', $texto, $resultado)) {
            $campos['referencia'] = Nf3eInvoiceText::fNormalizarReferencia($resultado[1]);
            $campos['val_total'] = $resultado[2];
            $campos['dat_vencimento'] = $resultado[3];
        }

        if (!isset($campos['referencia']) && preg_match('/\b(0[1-9]|1[0-2])\/(\d{4})\b\s+NOTA\s+FISCAL/iu', $texto, $resultado)) {
            $campos['referencia'] = $resultado[1] . '/' . $resultado[2];
        }

        return $campos;
    }

    /**
     * Extrai datas de leitura de linhas de classificação/tarifa
     *
     * O padrão espera leitura anterior, leitura atual, quantidade de dias e próxima
     * leitura na mesma sequência, que é o formato usual da área de consumo
     */
    private function fExtrairDatasLeitura(string $texto): array
    {
        $campos = [];

        if (preg_match('/(?:Classifica[çc][ãa]o:.*?|[AB]\s*-\s*[AB][1-4].*?)\s+(\d{2}\/\d{2}\/\d{4})\s+(\d{2}\/\d{2}\/\d{4})\s+\d{1,3}\s+(\d{2}\/\d{2}\/\d{4})/ius', $texto, $resultado)) {
            $campos['dat_leitura_anterior'] = $resultado[1];
            $campos['dat_leitura_atual'] = $resultado[2];
            $campos['dta_leitura_prox'] = $resultado[3];
        }

        return $campos;
    }

    /**
     * Reaproveita a mesma lógica para PIS/PASEP e COFINS, variando apenas o nome
     * do tributo aceito pela regex
     */
    private function fExtrairPercentualTributo(string $texto, string $tributo_regex): ?string
    {
        if (preg_match('/\b' . $tributo_regex . '\b\s+[0-9.]+,\d{2}\s+([0-9.]+,\d{1,4})%?/iu', $texto, $resultado)) {
            return rtrim($resultado[1], '%');
        }

        return null;
    }

    /**
     * Procura a chave de acesso priorizando as janelas próximas ao rótulo
     *
     * Primeiro exige dígito verificador válido; se o OCR corromper o DV, aceita
     * como fallback uma chave de 44 dígitos do modelo 66 encontrada no mesmo fluxo
     */
    private function fExtrairChaveAcesso(string $texto): ?string
    {
        $janelas_prioritarias = [];

        if (preg_match_all('/Chave\s+de\s+acesso\s*:?\s*([^\n]*(?:\n[^\n]*){0,2})/iu', $texto, $resultados)) {
            $janelas_prioritarias = $resultados[1];
        }

        foreach (array_merge($janelas_prioritarias, [$texto]) as $janela) {
            foreach ($this->fCandidatasChaveAcesso($janela) as $candidata) {
                if ($this->fChaveAcessoNf3eValida($candidata)) return $candidata;
            }
        }

        foreach (array_merge($janelas_prioritarias, [$texto]) as $janela) {
            foreach ($this->fCandidatasChaveAcesso($janela) as $candidata) {
                if (substr($candidata, 20, 2) === '66') return $candidata;
            }
        }

        return null;
    }

    /**
     * Gera candidatas de 44 dígitos a partir de trechos longos com separadores
     *
     * A janela deslizante é necessária porque o OCR pode colar a chave em outros
     * números da página; o array associativo remove duplicidades preservando o valor
     */
    private function fCandidatasChaveAcesso(string $texto): array
    {
        if (!preg_match_all('/[0-9][0-9\s.\-\/]{42,}[0-9]/u', $texto, $resultados)) {
            return [];
        }

        $candidatas = [];

        foreach ($resultados[0] as $trecho) {
            $digitos = Nf3eInvoiceText::FsomenteDigitos($trecho);
            $tamanho = strlen($digitos);

            for ($indice = 0; $indice <= $tamanho - 44; $indice++) {
                $candidata = substr($digitos, $indice, 44);
                $candidatas[$candidata] = true;
            }
        }

        return array_keys($candidatas);
    }


    // Valida a chave como NF3e: 44 dígitos, modelo 66 e dígito verificador correto
    private function fChaveAcessoNf3eValida(string $chave): bool
    {
        if (!preg_match('/^\d{44}$/', $chave)) return false;
        if (substr($chave, 20, 2) !== '66') return false;

        $base = substr($chave, 0, 43);

        return $this->fCalcularDigitoChaveAcesso($base) === (int) $chave[43];
    }

    // Calcula o dígito verificador da chave de acesso pelo módulo 11 oficial
    private function fCalcularDigitoChaveAcesso(string $base): int
    {
        $soma = 0;
        $peso = 2;

        for ($indice = strlen($base) - 1; $indice >= 0; $indice--) {
            $soma += (int) $base[$indice] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }

    /**
     * Extrai subgrupo e modalidade tarifária
     *
     * As primeiras regexes tratam linhas completas de classificação com maior
     * confiança; os padrões finais buscam cada campo separadamente como fallback
     */
    private function fExtrairClassificacao(string $texto): array
    {
        $modalidades = 'VERDE|AZUL|CONVENCIONAL|BRANCA';

        if (preg_match('/\b[AB]\s*-\s*(A[1-4]|B[1-4])\s*-\s*(' . $modalidades . ')\b/iu', $texto, $resultado)) {
            return [
                'cod_subgrupo' => strtoupper($resultado[1]),
                'codigo_modalidade' => strtoupper($resultado[2]),
            ];
        }

        if (preg_match('/Classifica[çc][ãa]o:\s*[AB]\s+([AB][1-4]).*?\b(?:THS_)?(' . $modalidades . ')\b/ius', $texto, $resultado)) {
            return [
                'cod_subgrupo' => strtoupper($resultado[1]),
                'codigo_modalidade' => strtoupper($resultado[2]),
            ];
        }

        $campos = [];

        $subgrupo = $this->fBuscarPrimeiro($texto, [
            '/SUBGRUPO\s*[:\-]?\s*(A[1-4]|B[1-4])\b/iu',
            '/\b(A[1-4]|B[1-4])\b/u',
        ]);

        if ($subgrupo !== null) {
            $campos['cod_subgrupo'] = strtoupper($subgrupo);
        }

        $modalidade = $this->fBuscarPrimeiro($texto, [
            '/MODALIDADE\s*(?:TARIF[ÁA]RIA)?\s*[:\-]?\s*(' . $modalidades . ')\b/iu',
            '/THS_(' . $modalidades . ')\b/iu',
        ]);

        if ($modalidade !== null) {
            $campos['codigo_modalidade'] = strtoupper($modalidade);
        }

        return $campos;
    }

    /**
     * Retorna o primeiro grupo capturado pelo primeiro padrão compatível
     *
     * A ordem dos padrões deve ir do mais específico para o mais permissivo
     */
    private function fBuscarPrimeiro(string $texto, array $padroes): ?string
    {
        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $texto, $resultado)) {
                return trim($resultado[1]);
            }
        }

        return null;
    }
}
