<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);

echo json_encode([
    'sucesso' => false,
    'mensagem' => 'O endpoint antigo foi desativado. Utilize /src/api/importar.php.'
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
