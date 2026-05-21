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
    // codigo da distribuidora presente em cada uc por distribuidora
    public const CODIGO_DISTRIBUIDORA = [
        '30460297000139' => '103', // Castrolanda
        '06981180000116' => '018', // Cemig
        '52777034000190' => '106', // Cemirim
        '49606312000132' => '086', // Ceripa
        '97081434000103' => '093', // Cermissões
        '09257558000121' => '095', // Certel
        '75805895000130' => '027', // Cocel
        '89435598000155' => '101', // Creral

        '28152650000171' => '054', // EDP_ES
        '02302100000106' => '004', // EDP_SP

        '04065033000170' => '046', // Energisa_AC
        '19527639007837' => '050', // Energisa_Minas_Rio
        '15413826000150' => '051', // Energisa_MS
        '03467321000199' => '017', // Energisa_MT
        '09095183000140' => '053', // Energisa_PB
        '05914650000166' => '020', // Energisa_RO
        '13017462000163' => '055', // Energisa_SE
        '07282377000120' => '006', // Energisa_Sul_Sudeste_SP
        '25086034000171' => '015', // Energisa_TO

        '12272084000100' => '008', // Equatorial_Alagoas
        '01543032000104' => '012', // Equatorial_Goias
        '06272793000184' => '016', // Equatorial_Maranhão
        '04895728000180' => '013', // Equatorial_Pará
        '06840748000189' => '019', // Equatorial_Piauí

        '91982348000187' => '057', // Hidropan
        '60444437000146' => '059', // Light

        '13255658000196' => '062', // Sulgipe
    ];

    /**
     * Orquestra as extrações gerais e os refinamentos por layout
     *
     * A chave de acesso é avaliada primeiro porque, quando válida, permite derivar
     * CNPJ do emitente, série e número da NF diretamente da estrutura oficial. Em
     * seguida, padrões mais específicos do texto podem confirmar ou sobrescrever campos
     */
    public function fExtraiCamposNf3e(string $texto): array
    {
        $campos = [];
        $texto_normalizado = Nf3eInvoiceText::fNormalizaDadosNf3e($texto);

        $chave_acesso = $this->fExtraiChaveAcesso($texto);
        if ($chave_acesso !== null) {
            $campos['chave_acesso'] = $chave_acesso;

            if (strlen($chave_acesso) === 44) {
                $campos['num_cnpj_emit'] = substr($chave_acesso, 6, 14);
                $campos['serie'] = substr($chave_acesso, 22, 3);
                $campos['num_nf'] = substr($chave_acesso, 25, 9);
            }
        }

        $nota_fiscal = $this->fBuscaPrimeiro($texto_normalizado, [
            '/NOTA\s+FISCAL\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})/iu',
            '/\bNF\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})/iu',
        ]);

        if ($nota_fiscal !== null) {
            $campos['num_nf'] = $nota_fiscal;
        }

        $serie = $this->fBuscaPrimeiro($texto_normalizado, [
            '/S[ÉE]RIE\s*[-:]?\s*(\d{1,3})/iu',
        ]);

        if ($serie !== null) {
            $campos['serie'] = str_pad($serie, 3, '0', STR_PAD_LEFT);
        }

        $campos = array_merge($campos, $this->fExtraiCabecalhoFatura($texto_normalizado));
        $campos = array_merge($campos, $this->fExtraiResumoPagamento($texto_normalizado));

        // Evita confundir CNPJ do destinatário com o do emitente já derivado da chave
        $cnpj_destinatario = $this->fBuscaPrimeiro($texto_normalizado, [
            '/(?:PAGADOR\s*\/\s*CPF:.*?|PAGADOR:.*?|CLIENTE:.*?|CONSUMIDOR:.*?|DESTINAT[ÁA]RIO:.*?)CNPJ(?:\/CPF)?\s*[:\-]?\s*([0-9*.]{2}\.[0-9*.]{3}\.[0-9*.]{3}\/[0-9*.]{4}-[0-9*.]{2})/ius',
            '/CNPJ(?:\/CPF)?\s*[:\-]?\s*([0-9*.]{2}\.[0-9*.]{3}\.[0-9*.]{3}\/[0-9*.]{4}-[0-9*.]{2})/iu',
            '/CNPJ(?:\/CPF)?\s*[:\-]?\s*([0-9*]{14})/iu',
        ]);

        if ($cnpj_destinatario !== null && (!isset($campos['num_cnpj_emit']) || Nf3eInvoiceText::fSomenteDigitos($cnpj_destinatario) !== $campos['num_cnpj_emit'])) {
            $campos['num_cnpj_dest'] = $cnpj_destinatario;
        }

        $unidade_consumo = $this->fExtraiUnidadeConsumoPadronizada($texto_normalizado, $campos['num_cnpj_emit'] ?? null);

        if ($unidade_consumo !== null) {
            $campos['cod_unidade_consumo'] = $unidade_consumo;
        }

        $campos = array_merge($campos, $this->fExtraiDatasLeitura($texto_normalizado));

        $pct_pis = $this->fExtraiPercentualTributo($texto_normalizado, '(?:PIS(?:\/PASEP)?|PASEP)');
        if ($pct_pis !== null) {
            $campos['pct_pis'] = $pct_pis;
        }

        $pct_cofins = $this->fExtraiPercentualTributo($texto_normalizado, 'COFINS');
        if ($pct_cofins !== null) {
            $campos['pct_cofins'] = $pct_cofins;
        }

        return array_merge($campos, $this->fExtraiClassificacao($texto_normalizado));
    }

    /**
     * Lê campos do cabeçalho da fatura, cobrindo layouts em linha única e layouts
     * onde os rótulos ficam empilhados acima dos valores
     */
    private function fExtraiCabecalhoFatura(string $texto): array
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
            $campos['referencia'] = Nf3eInvoiceText::fNormalizaReferencia($resultado[1]);
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
    private function fExtraiResumoPagamento(string $texto): array
    {
        $campos = [];

        if (preg_match('/\b([A-Z]{3}\/\d{2,4})\b\s+R\$\s*[*\s]*([0-9.]+,\d{2})\s+(\d{2}\/\d{2}\/\d{4})/iu', $texto, $resultado)) {
            $campos['referencia'] = Nf3eInvoiceText::fNormalizaReferencia($resultado[1]);
            $campos['val_total'] = $resultado[2];
            $campos['dat_vencimento'] = $resultado[3];
        }

        if (!isset($campos['referencia']) && preg_match('/\b(0[1-9]|1[0-2])\/(\d{4})\b\s+NOTA\s+FISCAL/iu', $texto, $resultado)) {
            $campos['referencia'] = $resultado[1] . '/' . $resultado[2];
        }

        return $campos;
    }

    // Extrai a UC padronizada pela REN 1.095/2024 usando distribuidora e DVs
    private function fExtraiUnidadeConsumoPadronizada(string $texto, ?string $cnpj_emitente): ?string
    {
        if ($cnpj_emitente === null) return null;

        $cnpj_emitente = Nf3eInvoiceText::fSomenteDigitos($cnpj_emitente);
        $codigo_distribuidora = self::CODIGO_DISTRIBUIDORA[$cnpj_emitente] ?? null;
        if ($codigo_distribuidora === null) return null;

        $janelas_prioritarias = [];
        if (preg_match_all('/(?:N[ÚU]MERO\s+UC|\bUC\b|UNIDADE\s+CONSUMIDORA)[^\n]*(?:\n[^\n]*){0,3}/iu', $texto, $resultados)) {
            $janelas_prioritarias = $resultados[0];
        }

        foreach (array_merge($janelas_prioritarias, [$texto]) as $janela) {
            foreach ($this->fCandidatasUnidadeConsumo($janela) as $candidata) {
                if ($this->fUnidadeConsumoValida($candidata, $codigo_distribuidora)) {
                    return $candidata;
                }
            }
        }

        return null;
    }

    // Gera candidatas de UC aceitando apenas dígitos, pontos e traços
    private function fCandidatasUnidadeConsumo(string $texto): array
    {
        if (!preg_match_all('/(?<![\d.\/-])([0-9][0-9.-]{3,}[0-9])(?![\d.\/-])/u', $texto, $resultados, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $candidatas = [];

        foreach ($resultados[1] as $resultado) {
            [$candidata, $inicio] = $resultado;
            if ($this->fCandidataUnidadeConsumoVizinhaDeBarra($texto, $inicio, strlen($candidata))) continue;

            $digitos = Nf3eInvoiceText::fSomenteDigitos($candidata);
            $tamanho = strlen($digitos);
            if ($tamanho < 5 || $tamanho > 15) continue;

            $candidatas[$digitos] = true;
        }

        return array_keys($candidatas);
    }

    // Evita aceitar metade de pares como "UC / instalação", que usam divisor fora da regra
    private function fCandidataUnidadeConsumoVizinhaDeBarra(string $texto, int $inicio, int $tamanho): bool
    {
        $antes = substr($texto, 0, $inicio);
        $depois = substr($texto, $inicio + $tamanho);

        return (bool) preg_match('/\/\s*$/u', $antes) || (bool) preg_match('/^\s*\//u', $depois);
    }

    // Valida código da distribuidora e os dois dígitos verificadores da UC
    private function fUnidadeConsumoValida(string $digitos, string $codigo_distribuidora): bool
    {
        if (!preg_match('/^\d{5,15}$/', $digitos)) return false;

        $unidade_padronizada = str_pad($digitos, 15, '0', STR_PAD_LEFT);
        if (substr($unidade_padronizada, 10, 3) !== $codigo_distribuidora) return false;

        return substr($unidade_padronizada, 13, 2) === $this->fCalculaDigitosUnidadeConsumo($unidade_padronizada);
    }

    // Calcula N2N1 conforme o Anexo II do manual da REN 1.095/2024
    private function fCalculaDigitosUnidadeConsumo(string $unidade_padronizada): string
    {
        $n2 = $this->fCalculaDigitoVerificadorUnidadeConsumo(substr($unidade_padronizada, 0, 13));
        $n1 = $this->fCalculaDigitoVerificadorUnidadeConsumo(substr($unidade_padronizada, 1, 12) . $n2);

        return (string) $n2 . (string) $n1;
    }

    // Calcula um dígito por módulo 11 com os pesos definidos para a UC
    private function fCalculaDigitoVerificadorUnidadeConsumo(string $base): int
    {
        $pesos = [10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 10, 9, 8];
        $soma = 0;

        foreach ($pesos as $indice => $peso) {
            $soma += (int) $base[$indice] * $peso;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }

    /**
     * Extrai datas de leitura de linhas de classificação/tarifa
     *
     * O padrão espera leitura anterior, leitura atual, quantidade de dias e próxima
     * leitura na mesma sequência, que é o formato usual da área de consumo
     */
    private function fExtraiDatasLeitura(string $texto): array
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
    private function fExtraiPercentualTributo(string $texto, string $tributo_regex): ?string
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
    private function fExtraiChaveAcesso(string $texto): ?string
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
            $digitos = Nf3eInvoiceText::fSomenteDigitos($trecho);
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

        return $this->fCalculaDigitoChaveAcesso($base) === (int) $chave[43];
    }

    // Calcula o dígito verificador da chave de acesso pelo módulo 11 oficial
    private function fCalculaDigitoChaveAcesso(string $base): int
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
    private function fExtraiClassificacao(string $texto): array
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

        $subgrupo = $this->fBuscaPrimeiro($texto, [
            '/SUBGRUPO\s*[:\-]?\s*(A[1-4]|B[1-4])\b/iu',
            '/\b(A[1-4]|B[1-4])\b/u',
        ]);

        if ($subgrupo !== null) {
            $campos['cod_subgrupo'] = strtoupper($subgrupo);
        }

        $modalidade = $this->fBuscaPrimeiro($texto, [
            '/MODALIDADE\s*(?:TARIF[ÁA]RIA)?\s*[:\-]?\s*(' . $modalidades . ')\b/iu',
            '/THS_(' . $modalidades . ')\b/iu',
        ]);

        if ($modalidade === null) {
            $modalidade = $this->fExtraiModalidadeAzulVerde($texto);
        }

        if ($modalidade !== null) {
            $campos['codigo_modalidade'] = strtoupper($modalidade);
        }

        return $campos;
    }

    /**
     * Busca modalidade azul/verde mesmo quando o layout omite o rótulo clássico
     *
     * Linhas de bandeira tarifária são ignoradas no fallback amplo para evitar
     * confundir bandeira verde com modalidade verde.
     */
    private function fExtraiModalidadeAzulVerde(string $texto): ?string
    {
        $linhas_relevantes = [];
        foreach (explode("\n", $texto) as $linha) {
            if (preg_match('/BANDEIRA(?:\(S\))?\s+TARIF[ÁA]RIA|BANDEIRA\s+(?:VERDE|AMARELA|VERMELHA)/iu', $linha)) continue;
            $linhas_relevantes[] = $linha;
        }

        $texto_relevante = implode("\n", $linhas_relevantes);
        $padroes_prioritarios = [
            '/\bTHS[_\s-]*(AZUL|VERDE)\b/iu',
            '/MODALIDADE\s*(?:TARIF[ÁA]RIA)?[^\n]*(AZUL|VERDE)\b/iu',
            '/(?:^|\n)[^\n]*(?:CLASSIFICA[ÇC][ÃA]O|SUBGRUPO|TARIFA|TARIF[ÁA]RIA)[^\n]*\b(AZUL|VERDE)\b/iu',
        ];

        foreach ($padroes_prioritarios as $padrao) {
            if (preg_match($padrao, $texto_relevante, $resultado)) {
                return strtoupper($resultado[1]);
            }
        }

        $modalidades_encontradas = [];
        foreach ($linhas_relevantes as $linha) {
            if (preg_match_all('/\b(AZUL|VERDE)\b/iu', $linha, $resultados)) {
                foreach ($resultados[1] as $modalidade) {
                    $modalidades_encontradas[strtoupper($modalidade)] = true;
                }
            }
        }

        return count($modalidades_encontradas) === 1 ? array_key_first($modalidades_encontradas) : null;
    }

    /**
     * Retorna o primeiro grupo capturado pelo primeiro padrão compatível
     *
     * A ordem dos padrões deve ir do mais específico para o mais permissivo
     */
    private function fBuscaPrimeiro(string $texto, array $padroes): ?string
    {
        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $texto, $resultado)) {
                return trim($resultado[1]);
            }
        }

        return null;
    }
}
