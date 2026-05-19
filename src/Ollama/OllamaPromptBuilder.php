<?php

require_once __DIR__ . '/../FaturaNf3e/Nf3eInvoiceText.php';

/**
 * Monta os prompts enviados ao Ollama
 *
 * A classe centraliza o caminho do template principal e também cria prompts
 * menores para complementar apenas campos essenciais que não foram extraídos
 * pelas regras determinísticas
 */
class OllamaPromptBuilder
{
    private $caminho_prompt;
    private static $template_prompt_cache = [];

    public function __construct(string $caminho_prompt)
    {
        $this->caminho_prompt = $caminho_prompt;
    }

    public function fCaminhoPrompt(): string
    {
        return $this->caminho_prompt;
    }

    // Carrega o template principal do prompt e guarda em cache por caminho
    public function fCarregaTemplate(): string
    {
        if (isset(self::$template_prompt_cache[$this->caminho_prompt])) {
            return self::$template_prompt_cache[$this->caminho_prompt];
        }

        $template = file_get_contents($this->caminho_prompt);
        if ($template === false) {
            throw new Exception("Não foi possível ler o template de prompt em: " . $this->caminho_prompt);
        }

        self::$template_prompt_cache[$this->caminho_prompt] = $template;

        return $template;
    }

    // Monta um prompt curto para pedir à IA somente os campos essenciais ausentes
    public function fMontaPromptCamposAusentes(string $texto_original, string $texto_filtrado, array $campos_ausentes): string
    {
        $json_chaves = json_encode(array_fill_keys($campos_ausentes, null), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $trechos_relevantes = $this->fExtraiTrechosRelevantes($texto_original);

        return "Você é um extrator de campos faltantes de faturas de energia NF3-e.\n"
            . "Retorne APENAS um JSON válido com exatamente estas chaves:\n"
            . $json_chaves . "\n\n"
            . "Regras:\n"
            . "- Não escreva explicações, markdown ou texto fora do JSON.\n"
            . "- Se não encontrar um campo, use null.\n"
            . "- Preserve zeros à esquerda.\n"
            . "TRECHOS RELEVANTES:\n"
            . $trechos_relevantes . "\n\n"
            . "TEXTO FILTRADO:\n"
            . substr($texto_filtrado, 0, 6000);
    }

    // Seleciona linhas com maior chance de conter os campos faltantes
    private function fExtraiTrechosRelevantes(string $texto): string
    {
        $texto_normalizado = Nf3eInvoiceText::fNormalizaDadosNf3e($texto);
        $linhas = explode("\n", $texto_normalizado);
        $linhas_relevantes = [];
        $manter_proximas = 0;

        $padroes = [
            '/N[úu]mero\s+UC|\bUC\b|Unidade\s+consumidora|N[ºo]\s+do\s+cliente/iu',
            '/\b\d{4,20}\s*\/\s*\d{4,20}\b/u',
            '/R\$\s*[*.0-9]*,\d{2}/u',
            '/NOTA\s+FISCAL|REFER[ÊE]NCIA|DATA\s+DE\s+EMISS[ÃA]O|DATA\s+DE\s+VENCIMENTO|VALOR\s+DO\s+DOCUMENTO/iu',
            '/Classifica[çc][ãa]o|SUBGRUPO|MODALIDADE|THS_(?:VERDE|AZUL)|\b[A-B]\s*-\s*[AB][1-4]/iu',
        ];

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '') continue;

            $manter = $manter_proximas > 0;
            foreach ($padroes as $padrao) {
                if (preg_match($padrao, $linha)) {
                    $manter = true;
                    $manter_proximas = max($manter_proximas, 2);
                    break;
                }
            }

            if ($manter) $linhas_relevantes[] = $linha;
            if ($manter_proximas > 0) $manter_proximas--;
        }

        $trechos = implode("\n", array_slice(array_values(array_unique($linhas_relevantes)), 0, 80));

        return $trechos !== '' ? $trechos : substr($texto_normalizado, 0, 3000);
    }
}
