<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Extrator de Faturas IA</title>
</head>
<body>
    <h1>Extrator de Faturas</h1>
    <form action="processar.php" method="POST" enctype="multipart/form-data">
        <label>Selecione a Fatura (PDF):</label>
        <input type="file" name="fatura" accept="application/pdf" required>
        <button type="submit">Processar</button>
    </form>
</body>
</html>