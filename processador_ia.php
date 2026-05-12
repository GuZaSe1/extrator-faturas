<?php

function registrarDebug(string $nivel, string $mensagem, array $contexto = []): void
{
    $diretorio_logs = __DIR__ . '/logs';

    if (!is_dir($diretorio_logs)) mkdir($diretorio_logs, 0775, true);

    $linha = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($nivel),
        $mensagem,
        $contexto ? json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );

    file_put_contents($diretorio_logs . '/app.log', $linha, FILE_APPEND | LOCK_EX);
}

function resumoDebug(?string $valor, int $limite = 8192): array
{
    $valor = $valor ?? '';
    $tamanho = strlen($valor);

    return [
        'length' => $tamanho,
        'excerpt' => substr($valor, 0, $limite),
        'truncated' => $tamanho > $limite,
    ];
}

class processador_ia
{
    private $url_api = "http://localhost:11434/api/generate";
    private $modelo = "qwen2.5:7b";
    private $caminho_prompt = __DIR__ . "/prompts/fatura_nf3e.txt";
    private $tempo_conexao_segundos = 10;
    private $tempo_requisicao_segundos = 600;

    public function processarTextoFatura(string $texto, ?string $id_debug = null, bool $debug = true)
    {
        $id_debug = $id_debug ?: uniqid('ia_', true);
        $inicio_processamento = microtime(true);

        if ($debug) {
            registrarDebug('info', 'IA: inicio do processamento', [
                'run_id' => $id_debug,
                'api_url' => $this->url_api,
                'model' => $this->modelo,
                'prompt_path' => $this->caminho_prompt,
                'input_text' => resumoDebug($texto),
            ]);
        }

        // 1. Carrega o template do arquivo de texto
        $inicio_template = microtime(true);

        if (!file_exists($this->caminho_prompt)) {
            if ($debug) {
                registrarDebug('error', 'IA: template de prompt nao encontrado', [
                    'run_id' => $id_debug,
                    'prompt_path' => $this->caminho_prompt,
                    'step_duration_seconds' => $this->segundosDesde($inicio_template),
                    'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
                ]);
            }

            throw new Exception("Template de prompt não encontrado em: " . $this->caminho_prompt);
        }

        $template = file_get_contents($this->caminho_prompt);

        if ($debug) {
            registrarDebug('debug', 'IA: template carregado', [
                'run_id' => $id_debug,
                'template' => resumoDebug($template),
                'template_has_placeholder' => strpos($template, '{{TEXTO_PDF}}') !== false,
                'step_duration_seconds' => $this->segundosDesde($inicio_template),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        // 2. Reduz o texto bruto antes de enviar para a IA, preservando o original
        // para as extrações determinísticas feitas depois da resposta.
        $inicio_filtro = microtime(true);
        $texto_filtrado = $this->filtrarTextoFatura($texto);

        if ($debug) {
            registrarDebug('debug', 'IA: texto filtrado para prompt', [
                'run_id' => $id_debug,
                'original_text' => resumoDebug($texto, 2000),
                'filtered_text' => resumoDebug($texto_filtrado, 4000),
                'original_length' => strlen($texto),
                'filtered_length' => strlen($texto_filtrado),
                'step_duration_seconds' => $this->segundosDesde($inicio_filtro),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        // 3. Substitui o placeholder pelo texto filtrado do PDF
        $inicio_prompt = microtime(true);
        $prompt_completo = str_replace("{{TEXTO_PDF}}", $texto_filtrado, $template);

        if ($debug) {
            registrarDebug('debug', 'IA: prompt final montado', [
                'run_id' => $id_debug,
                'full_prompt' => resumoDebug($prompt_completo),
                'text_length' => strlen($texto),
                'filtered_text_length' => strlen($texto_filtrado),
                'step_duration_seconds' => $this->segundosDesde($inicio_prompt),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        // 4. Monta o payload para o Ollama
        $inicio_payload = microtime(true);
        $payload = [
            "model" => $this->modelo,
            "prompt" => $prompt_completo,
            "stream" => false,
            "format" => "json",
            "options" => [
                "temperature" => 0,
                "num_ctx" => 8192,
                "num_thread" => 5
            ]
        ];

        if ($debug) {
            registrarDebug('debug', 'IA: payload montado para Ollama', [
                'run_id' => $id_debug,
                'payload_model' => $payload['model'],
                'payload_stream' => $payload['stream'],
                'payload_format' => $payload['format'],
                'payload_options' => $payload['options'],
                'payload_prompt_length' => strlen($payload['prompt']),
                'connect_timeout_seconds' => $this->tempo_conexao_segundos,
                'request_timeout_seconds' => $this->tempo_requisicao_segundos,
                'step_duration_seconds' => $this->segundosDesde($inicio_payload),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $inicio_chamada_http = microtime(true);
        $curl = curl_init($this->url_api);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->tempo_conexao_segundos);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->tempo_requisicao_segundos);
        curl_setopt($curl, CURLOPT_NOSIGNAL, true);

        if ($debug) {
            registrarDebug('info', 'IA: chamada cURL iniciada', [
                'run_id' => $id_debug,
                'api_url' => $this->url_api,
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $resposta = curl_exec($curl);
        $erro_curl = curl_error($curl);
        $codigo_erro_curl = curl_errno($curl);
        $codigo_http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $tipo_conteudo = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $tempo_total = curl_getinfo($curl, CURLINFO_TOTAL_TIME);
        curl_close($curl);

        if ($debug) {
            registrarDebug($codigo_erro_curl ? 'error' : 'info', 'IA: chamada cURL finalizada', [
                'run_id' => $id_debug,
                'curl_errno' => $codigo_erro_curl,
                'curl_error' => $erro_curl,
                'http_code' => $codigo_http,
                'content_type' => $tipo_conteudo,
                'curl_total_time_seconds' => $tempo_total,
                'step_duration_seconds' => $this->segundosDesde($inicio_chamada_http),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
                'raw_response' => resumoDebug($resposta === false ? '' : $resposta, 2000),
            ]);
        }

        if ($resposta === false) {
            if ($codigo_erro_curl === CURLE_OPERATION_TIMEDOUT) {
                throw new Exception(
                    "Erro ao chamar Ollama: a geração excedeu {$this->tempo_requisicao_segundos}s sem resposta. " .
                        "O modelo {$this->modelo} pode estar lento para este prompt; tente novamente, use um modelo menor ou aumente o timeout em processador_ia.php."
                );
            }

            throw new Exception("Erro ao chamar Ollama: " . ($erro_curl ?: 'erro desconhecido'));
        }

        if ($codigo_http < 200 || $codigo_http >= 300) {
            throw new Exception("Erro ao chamar Ollama: HTTP {$codigo_http} - " . substr($resposta, 0, 500));
        }

        $inicio_decode_http = microtime(true);
        $dados_resposta = json_decode($resposta, true);
        $erro_json_externo = json_last_error_msg();

        if ($debug) {
            registrarDebug(json_last_error() === JSON_ERROR_NONE ? 'debug' : 'error', 'IA: resposta HTTP decodificada', [
                'run_id' => $id_debug,
                'json_error' => $erro_json_externo,
                'decoded_keys' => is_array($dados_resposta) ? array_keys($dados_resposta) : null,
                'step_duration_seconds' => $this->segundosDesde($inicio_decode_http),
                'elapsed_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        $json_bruto = $dados_resposta['response'] ?? '';

        if ($debug) {
            registrarDebug('debug', 'IA: campo response extraido', [
                'run_id' => $id_debug,
                'response_field' => resumoDebug($json_bruto, 2000),
            ]);
        }

        // Limpeza de segurança para as barras que a IA costuma adicionar
        $inicio_decode_resultado = microtime(true);
        $json_limpo = str_replace('\/', '/', $json_bruto);

        $resultado_decodificado = json_decode($json_limpo, true);
        $erro_json_interno = json_last_error_msg();

        if ($debug) {
            registrarDebug(json_last_error() === JSON_ERROR_NONE ? 'info' : 'error', 'IA: JSON final decodificado', [
                'run_id' => $id_debug,
                'json_error' => $erro_json_interno,
                'clean_json' => resumoDebug($json_limpo, 2000),
                'result_type' => gettype($resultado_decodificado),
                'result_keys' => is_array($resultado_decodificado) ? array_keys($resultado_decodificado) : null,
                'step_duration_seconds' => $this->segundosDesde($inicio_decode_resultado),
                'duration_seconds' => round(microtime(true) - $inicio_processamento, 4),
            ]);
        }

        if (!is_array($resultado_decodificado)) return $resultado_decodificado;

        $inicio_campos_deterministicos = microtime(true);
        $campos_fixos = $this->extrairCamposFixos($texto);
        $resultado_decodificado = $this->normalizarChavesResultado($resultado_decodificado);
        $resultado_decodificado = array_merge($resultado_decodificado, $campos_fixos);

        if ($debug) {
            registrarDebug('info', 'IA: campos determinísticos aplicados', [
                'run_id' => $id_debug,
                'fixed_fields' => $campos_fixos,
                'result_keys' => array_keys($resultado_decodificado),
                'step_duration_seconds' => $this->segundosDesde($inicio_campos_deterministicos),
                'duration_seconds' => $this->segundosDesde($inicio_processamento),
            ]);
        }

        return $resultado_decodificado;
    }

    private function normalizarChavesResultado(array $resultado): array
    {
        $resultado_normalizado = [];

        foreach ($resultado as $chave => $valor) {
            $resultado_normalizado[str_replace('-', '_', (string) $chave)] = $valor;
        }

        return $resultado_normalizado;
    }

    private function segundosDesde(float $inicio): float
    {
        return round(microtime(true) - $inicio, 4);
    }

    private function extrairCamposFixos(string $texto): array
    {
        $campos = [];
        $texto_normalizado = $this->normalizarTexto($texto);

        $chave_acesso = $this->extrairChaveAcesso($texto);
        if ($chave_acesso !== null) {
            $campos['chave_acesso'] = $chave_acesso;

            if (strlen($chave_acesso) === 44) {
                $campos['num_cnpj_emit'] = substr($chave_acesso, 6, 14);
                $campos['serie'] = substr($chave_acesso, 22, 3);
                $campos['num_nf'] = substr($chave_acesso, 25, 9);
            }
        }

        $nota_fiscal = $this->buscarPrimeiro($texto_normalizado, [
            '/NOTA\s+FISCAL\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})/iu',
            '/\bNF\s*(?:N[ºO.]*)?\s*[-:]?\s*(\d{6,12})/iu',
        ]);

        if ($nota_fiscal !== null) {
            $campos['num_nf'] = $nota_fiscal;
        }

        $serie = $this->buscarPrimeiro($texto_normalizado, [
            '/S[ÉE]RIE\s*[-:]?\s*(\d{1,3})/iu',
        ]);

        if ($serie !== null) {
            $campos['serie'] = str_pad($serie, 3, '0', STR_PAD_LEFT);
        }

        $cnpj_destinatario = $this->buscarPrimeiro($texto_normalizado, [
            '/(?:PAGADOR\s*\/\s*CPF:.*?|PAGADOR:.*?|CLIENTE:.*?|CONSUMIDOR:.*?|DESTINAT[ÁA]RIO:.*?)CNPJ\s*[:\-]?\s*([0-9*.]{2}\.[0-9*.]{3}\.[0-9*.]{3}\/[0-9*.]{4}-[0-9*.]{2})/ius',
            '/CNPJ\s*[:\-]?\s*([0-9*.]{2}\.[0-9*.]{3}\.[0-9*.]{3}\/[0-9*.]{4}-[0-9*.]{2})/iu',
            '/CNPJ\s*[:\-]?\s*([0-9*]{14})/iu',
        ]);

        if ($cnpj_destinatario !== null && (!isset($campos['num_cnpj_emit']) || $this->somenteDigitos($cnpj_destinatario) !== $campos['num_cnpj_emit'])) {
            $campos['num_cnpj_dest'] = $cnpj_destinatario;
        }

        $unidade_consumo = $this->buscarPrimeiro($texto_normalizado, [
            '/N[ÚU]MERO\s+UC\s+(\d{4,20})(?:\s*\/\s*\d{4,20})?/iu',
            '/\bUC\s*[:\-]?\s*(\d{4,20})(?:\s*\/\s*\d{4,20})?/iu',
            '/UNIDADE\s+CONSUMIDORA\s*[:\-]?\s*(\d{4,20})/iu',
        ]);

        if ($unidade_consumo !== null) {
            $campos['cod_unidade_consumo'] = $unidade_consumo;
        }

        $classificacao = $this->extrairClassificacao($texto_normalizado);
        if ($classificacao !== []) {
            $campos = array_merge($campos, $classificacao);
        }

        return $campos;
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[ \t]+/', ' ', $texto);
        $texto = preg_replace('/ *\n */', "\n", $texto);

        return trim($texto);
    }

    private function filtrarTextoFatura(string $texto): string
    {
        $texto = preg_replace('/(?:0,000)?DEMFP\s*/iu', ' ', $texto);
        $texto = $this->normalizarTexto($texto);

        if ($texto === '') {
            return $texto;
        }

        $linhas = explode("\n", $texto);
        $linhas_filtradas = [];
        $manter_proximas = 0;

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

        $padroes_manter = [
            '/N[úu]mero\s+UC|\bUC\b|Unidade\s+consumidora|N[ºo]\s+do\s+cliente/iu',
            '/PAGADOR|CLIENTE|CONSUMIDOR|DESTINAT[ÁA]RIO|CNPJ|CPF|INSC\.?\s*EST/iu',
            '/NOTA\s+FISCAL|\bNF\b|S[ÉE]RIE|Chave\s+de\s+acesso|N[ºo]\s+CONTROLE/iu',
            '/DATA\s+DE\s+EMISS[ÃA]O|REFER[ÊE]NCIA|DATA\s+DE\s+VENCIMENTO|VALOR\s+DO\s+DOCUMENTO/iu',
            '/Data\s+de\s+apresenta[çc][ãa]o|Leitura\s+Anterior|Leitura\s+Atual|Pr[óo]x(?:ima)?\s+Leitura/iu',
            '/\b[A-B]\s*-\s*[AB][1-4]\s*-\s*(VERDE|AZUL|CONVENCIONAL|BRANCA)\b/iu',
            '/SUBGRUPO|MODALIDADE\s*TARIF[ÁA]RIA|Bandeira\(s\)\s+tarif[áa]ria/iu',
            '/PIS\/PASEP|\bPIS\b|COFINS|\bICMS\b/iu',
            '/Demanda\s+kW|Consumo\s+Faturado|N[ºo]\s+DIAS|Hora\s+Ponta|Hora\s+Fora\s+Ponta/iu',
            '/\b(?:JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\/\d{2}\b/iu',
            '/Demanda\s+-\s*KW|Demanda\s+de\s+Gera[çc][ãa]o|Demanda\s+Contratada/iu',
            '/Itens\s+de\s+Fatura|Unid\.|Quant\.|Pre[çc]o\s+unit|Valor\s+\(R\$\)|Tarifa\s+unit/iu',
            '/CONSUMO|DEMANDA|UFER|REATIV|ENCARGO|CIP|BENEF[ÍI]CIO|DEDU[ÇC][ÃA]O|DIF\.?FATUR|TUSD|TE\b|HOMOLOG/iu',
            '/Subtotal|TOTAL\b/iu',
            '/\b\d{2}\/\d{2}\/\d{4}\b|\b\d{2}\/\d{4}\b|R\$\s*\d/iu',
            '/(?:\d[\s.]*){44}/u',
        ];

        foreach ($linhas as $linha) {
            $linha = trim($linha);

            if ($linha === '') continue;
            $linha = preg_replace('/^\d{6,}(?=N[ÚU]mero\s+UC)/iu', '', $linha);

            if (preg_match('/^[\d\s-]+$/u', $linha) && strlen($this->somenteDigitos($linha)) > 44) continue;

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

            $linha = $this->limparLinhaProduto($linha);
            $linhas_filtradas[] = $linha;

            if (preg_match('/^(?:DATA\s+DE\s+EMISS[ÃA]O|REFER[ÊE]NCIA|DATA\s+DE\s+VENCIMENTO|VALOR\s+DO\s+DOCUMENTO|NOTA\s+FISCAL|Chave\s+de\s+acesso|PAGADOR\s*\/\s*CPF)\s*:?\s*$/iu', $linha)) {
                $manter_proximas = max($manter_proximas, 2);
            } elseif ($manter_proximas > 0) {
                $manter_proximas--;
            }
        }

        return implode("\n", $linhas_filtradas);
    }

    private function limparLinhaProduto(string $linha): string
    {
        $descricoes_produto_regex = implode('|', [
            'DEMANDA\s+[ÚU]NICA',
            'DIF\.?\s*FATUR',
            'CONSUMO\s+ATIVO',
            'DEMANDA\s+LEI',
            'ENCARGO\s+ESCASSEZ',
            'BENEF[ÍI]CIO\s+TARIF[ÁA]RIO',
            'CIP[-\s]',
        ]);

        if (preg_match('/((' . $descricoes_produto_regex . ').*)$/iu', $linha, $resultado)) {
            return trim($resultado[1]);
        }

        if (preg_match('/((UFER\s+).*)$/iu', $linha, $resultado)) {
            return trim($resultado[1]);
        }

        $inicio_produto = null;
        $descricoes_produto = [
            'DEMANDA\s+[ÚU]NICA',
            'DIF\.?\s*FATUR',
            'CONSUMO\s+ATIVO',
            'UFER\s+',
            'DEMANDA\s+LEI',
            'ENCARGO\s+ESCASSEZ',
            'BENEF[ÍI]CIO\s+TARIF[ÁA]RIO',
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

    private function extrairChaveAcesso(string $texto): ?string
    {
        if (preg_match('/Chave\s+de\s+acesso\s*:\s*((?:\d[\s.]*){44})/iu', $texto, $resultado)) {
            return $this->somenteDigitos($resultado[1]);
        }

        if (preg_match_all('/(?:\d[\s.]*){44}/', $texto, $resultados)) {
            foreach ($resultados[0] as $candidata) {
                $digitos = $this->somenteDigitos($candidata);
                if (strlen($digitos) === 44) return $digitos;
            }
        }

        return null;
    }

    private function extrairClassificacao(string $texto): array
    {
        $modalidades = 'VERDE|AZUL|CONVENCIONAL|BRANCA';

        if (preg_match('/\b[AB]\s*-\s*(A[1-4]|B[1-4])\s*-\s*(' . $modalidades . ')\b/iu', $texto, $resultado)) {
            return [
                'cod_subgrupo' => strtoupper($resultado[1]),
                'codigo_modalidade' => strtoupper($resultado[2]),
            ];
        }

        $campos = [];

        $subgrupo = $this->buscarPrimeiro($texto, [
            '/SUBGRUPO\s*[:\-]?\s*(A[1-4]|B[1-4])\b/iu',
            '/\b(A[1-4]|B[1-4])\b/u',
        ]);

        if ($subgrupo !== null) {
            $campos['cod_subgrupo'] = strtoupper($subgrupo);
        }

        $modalidade = $this->buscarPrimeiro($texto, [
            '/MODALIDADE\s*(?:TARIF[ÁA]RIA)?\s*[:\-]?\s*(' . $modalidades . ')\b/iu',
        ]);

        if ($modalidade !== null) {
            $campos['codigo_modalidade'] = strtoupper($modalidade);
        }

        return $campos;
    }

    private function buscarPrimeiro(string $texto, array $padroes): ?string
    {
        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $texto, $resultado)) {
                return trim($resultado[1]);
            }
        }

        return null;
    }

    private function somenteDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor);
    }
}
