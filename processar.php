<?php
// processar.php
require 'vendor/autoload.php';
require 'processador_ia.php';

use Smalot\PdfParser\Parser;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fatura'])) {
    $file = $_FILES['fatura'];

    // 1. Validação básica de segurança
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Erro no upload.");
    if ($file['type'] !== 'application/pdf') throw new Exception("Apenas PDFs são aceitos.");

    // 2. Extração de Texto do PDF
    $parser = new Parser();
    $pdf = $parser->parseFile($file['tmp_name']);
    $text = $pdf->getText();

    
    if (empty(trim($text))) {
        throw new Exception("O PDF parece estar vazio ou é uma imagem (precisa de OCR).");
    }

    // 3. Chamada da IA via Classe Modular
    $ai = new processador_ia();
    $dadosGeral = $ai->processInvoiceText($text);

    // 4. Exibição do Resultado (Por enquanto na tela)
    echo "<h2>Dados Extraídos com Sucesso!</h2>";
    echo "<pre>" . json_encode($dadosGeral, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    echo "<br><a href='index.php'>Voltar</a>";
}
