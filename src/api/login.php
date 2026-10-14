<?php
declare(strict_types=1);

/*
 * API de login.
 * Endpoint:
 * POST /src/api/login.php   corpo JSON {"email": "...", "senha": "..."} (ou form-data com os mesmos campos)
 *
 * Devolve um token que deve ser enviado nas demais chamadas: Authorization: Bearer <token>.
 * Se usuario.trocar_senha vier true, o token só serve para trocar-senha.php.
 *
 * Primeiro acesso (banco sem nenhum usuário): entre com o administrador padrão de
 * src/backend/UsuarioPadrao.php e troque a senha.
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
require __DIR__ . '/../backend/UsuarioPadrao.php';

// Banco sem nenhum usuário (primeiro acesso): cria o administrador padrão.
UsuarioPadrao::criarSeNecessario($con);

$corpo = $_POST;
if (!$corpo) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    $corpo = is_array($json) ? $json : [];
}

$email = is_string($corpo['email'] ?? null) ? trim($corpo['email']) : '';
$senha = is_string($corpo['senha'] ?? null) ? $corpo['senha'] : '';

if ($email === '' || $senha === '') {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Informe email e senha.'
    ]);
}

$sessao = (new Autenticacao($con))->entrar($email, $senha);

if ($sessao === null) {
    responder(401, [
        'sucesso' => false,
        'mensagem' => 'E-mail ou senha inválidos.'
    ]);
}

responder(200, [
    'sucesso' => true,
    'dados' => $sessao
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
