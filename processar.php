<?php
require 'vendor/autoload.php';
require 'processador_ia.php';

use Spatie\PdfToText\Pdf;

if (!function_exists('mensagemErroUpload')) {
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
}

$debug_config = $_POST['debug'] ?? $_GET['debug'] ?? getenv('EXTRATOR_DEBUG');
if ($debug_config === false || $debug_config === null) {
    $debug_config = false;
}
$debug = filter_var($debug_config, FILTER_VALIDATE_BOOLEAN);
$id_debug = uniqid('req_', true);
$inicio_requisicao = microtime(true);

if ($debug) {
    registrarDebug('info', 'Requisicao recebida em processar.php', [
        'run_id' => $id_debug,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
        'files_keys' => array_keys($_FILES ?? []),
        'post_keys' => array_keys($_POST ?? []),
    ]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['fatura'])) {
        if ($debug) {
            registrarDebug('warning', 'Requisicao ignorada: metodo invalido ou arquivo ausente', [
                'run_id' => $id_debug,
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'has_fatura' => isset($_FILES['fatura']),
            ]);
        }

        echo "<p>Nenhum arquivo enviado.</p>";
        echo "<br><a href='index.php'>Voltar</a>";
        exit;
    }

    $arquivo = $_FILES['fatura'];

    if ($debug) {
        registrarDebug('debug', 'Upload recebido', [
            'run_id' => $id_debug,
            'name' => $arquivo['name'] ?? null,
            'type' => $arquivo['type'] ?? null,
            'tmp_name' => $arquivo['tmp_name'] ?? null,
            'error' => $arquivo['error'] ?? null,
            'error_message' => mensagemErroUpload((int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE)),
            'size' => $arquivo['size'] ?? null,
            'is_uploaded_file' => isset($arquivo['tmp_name']) ? is_uploaded_file($arquivo['tmp_name']) : false,
        ]);
    }

    // 1. Validação básica de segurança
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        if ($debug) {
            registrarDebug('error', 'Falha na validacao de upload', [
                'run_id' => $id_debug,
                'error' => $arquivo['error'],
                'error_message' => mensagemErroUpload((int) $arquivo['error']),
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
            registrarDebug('error', 'Arquivo rejeitado por MIME type', [
                'run_id' => $id_debug,
                'received_type' => $arquivo['type'],
                'detected_type' => $mime_real,
                'expected_type' => 'application/pdf',
            ]);
        }

        throw new Exception("Apenas PDFs são aceitos.");
    }

    if ($debug) {
        registrarDebug('info', 'Iniciando extracao de texto do PDF', [
            'run_id' => $id_debug,
            'tmp_name' => $arquivo['tmp_name'],
            'file_size' => filesize($arquivo['tmp_name']),
        ]);
    }

    // 2. Extração de Texto do PDF
    $inicio_extracao_pdf = microtime(true);
    $texto = (new Pdf())
        ->setPdf($arquivo['tmp_name'])
        ->setOptions(['layout'])
        ->text();

    if ($debug) {
        registrarDebug('debug', 'Texto extraido do PDF', [
            'run_id' => $id_debug,
            'text_summary' => resumoDebug($texto, 2000),
            'step_duration_seconds' => round(microtime(true) - $inicio_extracao_pdf, 4),
            'elapsed_seconds' => round(microtime(true) - $inicio_requisicao, 4),
        ]);
    }

    if (empty(trim($texto))) {
        if ($debug) {
            registrarDebug('error', 'PDF sem texto extraivel', [
                'run_id' => $id_debug,
                'text_length' => strlen($texto),
            ]);
        }

        throw new Exception("O PDF parece estar vazio ou é uma imagem (precisa de OCR).");
    }

    // 3. Chamada da IA via Classe Modular
    if ($debug) {
        registrarDebug('info', 'Chamando processador de IA', [
            'run_id' => $id_debug,
            'text_length' => strlen($texto),
        ]);
    }

    $processador_ia = new processador_ia();
    $dados_gerais = $processador_ia->processarTextoFatura($texto, $id_debug, $debug);

    if ($debug) {
        registrarDebug('info', 'Processamento concluido com sucesso', [
            'run_id' => $id_debug,
            'result_type' => gettype($dados_gerais),
            'result_keys' => is_array($dados_gerais) ? array_keys($dados_gerais) : null,
            'duration_seconds' => round(microtime(true) - $inicio_requisicao, 4),
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
        registrarDebug('error', 'Erro geral no processamento', [
            'run_id' => $id_debug,
            'message' => $erro->getMessage(),
            'file' => $erro->getFile(),
            'line' => $erro->getLine(),
            'trace' => $erro->getTraceAsString(),
            'duration_seconds' => round(microtime(true) - $inicio_requisicao, 4),
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
