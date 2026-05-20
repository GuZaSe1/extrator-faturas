<?php
require 'vendor/autoload.php';
require 'processador_ia.php';

use Spatie\PdfToText\Pdf;

function mensagemErroUpload(int $codigo_erro): string
{
    $mensagens = [
        UPLOAD_ERR_OK => 'Upload realizado com sucesso.',
        UPLOAD_ERR_INI_SIZE => 'Arquivo excede upload_max_filesize do php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'Arquivo excede MAX_FILE_SIZE do formulario.',
        UPLOAD_ERR_PARTIAL => 'Upload parcial.',
        UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado.',
        UPLOAD_ERR_NO_TMP_DIR => 'Diretorio temporario ausente.',
        UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever arquivo em disco.',
        UPLOAD_ERR_EXTENSION => 'Upload interrompido por extensao PHP.',
    ];

    return $mensagens[$codigo_erro] ?? 'Erro de upload desconhecido.';
}

$debug_config = $_POST['debug'] ?? $_GET['debug'] ?? getenv('EXTRATOR_DEBUG');
if ($debug_config === false || $debug_config === null) {
    $debug_config = false;
}

$debug = filter_var($debug_config, FILTER_VALIDATE_BOOLEAN);
$id_debug = uniqid('req_', true);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['fatura'])) {
        if ($debug) {
            registrarDebug('aviso', 'Requisicao ignorada: metodo invalido ou arquivo ausente', [
                'id_debug' => $id_debug,
                'metodo' => $_SERVER['REQUEST_METHOD'] ?? null,
                'tem_fatura' => isset($_FILES['fatura']),
            ]);
        }

        echo "<p>Nenhum arquivo enviado.</p>";
        echo "<br><a href='index.php'>Voltar</a>";
        exit;
    }

    $arquivo = $_FILES['fatura'];

    // 1. Validação básica de segurança
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        if ($debug) {
            registrarDebug('erro', 'Falha na validacao de upload', [
                'id_debug' => $id_debug,
                'erro' => $arquivo['error'],
                'mensagem_erro' => mensagemErroUpload((int) $arquivo['error']),
            ]);
        }

        throw new Exception("Erro no upload: " . mensagemErroUpload((int) $arquivo['error']));
    }

    $tamanho_maximo = 4000 * 1024;
    if (($arquivo['size'] ?? 0) > $tamanho_maximo) {
        throw new Exception("Arquivo muito grande. Envie um PDF de até 4000 KB.");
    }

    $detector_mime = new finfo(FILEINFO_MIME_TYPE);
    $mime_real = $detector_mime->file($arquivo['tmp_name']) ?: '';

    if ($mime_real !== 'application/pdf') {
        if ($debug) {
            registrarDebug('erro', 'Arquivo rejeitado por MIME type', [
                'id_debug' => $id_debug,
                'tipo_recebido' => $arquivo['type'],
                'tipo_detectado' => $mime_real,
                'tipo_esperado' => 'application/pdf',
            ]);
        }

        throw new Exception("Apenas PDFs são aceitos.");
    }

    // 2. Extração de Texto do PDF
    $texto = (new Pdf())
        ->setPdf($arquivo['tmp_name'])
        ->setOptions(['layout'])
        ->text();

    if ($debug) {
        registrarDebug('informacao', 'Texto extraido do PDF', [
            'id_debug' => $id_debug,
            'nome_arquivo' => $arquivo['name'] ?? null,
            'tamanho_arquivo' => filesize($arquivo['tmp_name']),
            'resumo_texto' => resumoDebug($texto, 2000),
        ]);
    }

    if (empty(trim($texto))) {
        if ($debug) {
            registrarDebug('erro', 'PDF sem texto extraivel', [
                'id_debug' => $id_debug,
                'tamanho_texto' => strlen($texto),
            ]);
        }

        throw new Exception("O PDF parece estar vazio ou é uma imagem (precisa de OCR).");
    }

    // 3. Processamento modular da fatura
    $processador_ia = new processador_ia();
    $dados_gerais = $processador_ia->fProcessaTextoNf3e($texto, $id_debug, $debug);

    if ($debug) {
        registrarDebug('informacao', 'Processamento finalizado', [
            'id_debug' => $id_debug,
            'situacao' => 'sucesso',
        ]);
    }

    // 4. Exibição do Resultado
    echo "<h2>Dados Extraídos com Sucesso!</h2>";
    $json_saida = json_encode($dados_gerais, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo "<pre>" . htmlspecialchars($json_saida, ENT_QUOTES, 'UTF-8') . "</pre>";
    if ($debug) {
        echo "<p><strong>Debug Run ID:</strong> " . htmlspecialchars($id_debug, ENT_QUOTES, 'UTF-8') . "</p>";
    }
    echo "<br><a href='index.php'>Voltar</a>";
} catch (Throwable $erro) {
    if ($debug) {
        registrarDebug('erro', 'Processamento finalizado', [
            'id_debug' => $id_debug,
            'situacao' => 'erro',
            'mensagem' => $erro->getMessage(),
            'arquivo' => $erro->getFile(),
            'linha' => $erro->getLine(),
            'rastreamento' => $erro->getTraceAsString(),
        ]);
    }

    http_response_code(500);
    echo "<h2>Erro ao processar fatura</h2>";
    echo "<pre>" . htmlspecialchars($erro->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
    if ($debug) {
        echo "<p><strong>Debug Run ID:</strong> " . htmlspecialchars($id_debug, ENT_QUOTES, 'UTF-8') . "</p>";
        echo "<p>Veja detalhes em <code>logs/app.log</code>.</p>";
    }
    echo "<br><a href='index.php'>Voltar</a>";
}
