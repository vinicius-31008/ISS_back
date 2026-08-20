<?php
header('Content-Type: text/html; charset=utf-8');

require __DIR__ . '/conexao.php';
require __DIR__ . '/backend/CalculadoraIss.php';
require __DIR__ . '/backend/ClassificadorServico.php';
require __DIR__ . '/backend/ImportadorTxt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
    die('Nenhum arquivo válido foi enviado. <br><a href="index.php">Voltar</a>');
}

$nomeOriginal = basename($_FILES['arquivo']['name']);
if (strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION)) !== 'txt') {
    die('Apenas arquivos .txt são aceitos. <br><a href="index.php">Voltar</a>');
}

$conteudo = file_get_contents($_FILES['arquivo']['tmp_name']);
if ($conteudo === false) {
    die('Não foi possível ler o arquivo.');
}

try {
    $importador = new ImportadorTxt($con, new ClassificadorServico(), new CalculadoraIss());
    $resultado = $importador->processar($nomeOriginal, $conteudo);
} catch (Throwable $e) {
    http_response_code(500);
    die('Falha no processamento: ' . htmlspecialchars($e->getMessage()) . '<br><a href="index.php">Voltar</a>');
}

$issTotal = number_format($resultado['issTotal'], 2, ',', '.');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Resultado fiscal</title>
</head>
<body>
    <h1>Resultado da importação fiscal</h1>
    <p>Importação #<?= (int)$resultado['importacaoId'] ?></p>
    <p><strong><?= (int)$resultado['totalLinhas'] ?></strong> linha(s) lida(s).</p>
    <p><strong><?= (int)$resultado['processadas'] ?></strong> nota(s) processada(s).</p>
    <p><strong><?= count($resultado['erros']) ?></strong> erro(s).</p>
    <p><strong>ISS calculado: R$ <?= $issTotal ?></strong></p>

    <?php if ($resultado['erros']): ?>
        <h2>Erros</h2>
        <ul>
            <?php foreach ($resultado['erros'] as $erro): ?>
                <li>Linha <?= (int)$erro['linha'] ?>: <?= htmlspecialchars($erro['mensagem'], ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <a href="index.php">Nova importação</a>
</body>
</html>
