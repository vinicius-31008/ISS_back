<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Utilize GET.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'sucesso' => true,
    'servico' => 'Importador Fiscal ISS API',
    'status' => 'online',
    'versao' => '1.1.0'
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
