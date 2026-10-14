<?php
declare(strict_types=1);

/*
 * API de logout: invalida o token enviado em Authorization: Bearer <token>.
 * Endpoint:
 * POST /src/api/logout.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

set_exception_handler(function (Throwable $e): void {
    error_log((string)$e);
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Erro interno no backend.'
    ]);
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido. Utilize POST.'
    ]);
}

require __DIR__ . '/../conexao.php';
require __DIR__ . '/../backend/Autenticacao.php';

$token = Autenticacao::tokenDaRequisicao();

if ($token !== '') {
    (new Autenticacao($con))->sair($token);
}

// Responde sucesso mesmo com token já inválido: o resultado (sessão encerrada) é o mesmo.
responder(200, [
    'sucesso' => true,
    'mensagem' => 'Sessão encerrada.'
]);

function responder(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    exit;
}
