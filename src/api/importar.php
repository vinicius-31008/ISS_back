<?php
declare(strict_types=1);

/*
 * API de importação fiscal.
 * Endpoint:
 * POST /ImportadorFiscalISS/src/api/importar.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

set_exception_handler(function (Throwable $e): void {
    error_log((string)$e);
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Erro interno no backend.',
        'erro' => $e->getMessage()
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
require __DIR__ . '/../backend/CalculadoraIss.php';
require __DIR__ . '/../backend/ClassificadorServico.php';
require __DIR__ . '/../backend/ImportadorTxt.php';

if (!isset($_FILES['arquivo'])) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'O arquivo TXT deve ser enviado no campo "arquivo".'
    ]);
}

$arquivo = $_FILES['arquivo'];

if (!isset($arquivo['error']) || $arquivo['error'] !== UPLOAD_ERR_OK) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => mensagemUpload((int)($arquivo['error'] ?? -1))
    ]);
}

$nomeOriginal = basename((string)$arquivo['name']);
$extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));

if ($extensao !== 'txt') {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Apenas arquivos .txt são aceitos.'
    ]);
}

if (!is_uploaded_file($arquivo['tmp_name'])) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Arquivo enviado inválido.'
    ]);
}

$conteudo = file_get_contents($arquivo['tmp_name']);

if ($conteudo === false) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível ler o arquivo enviado.'
    ]);
}

if (trim($conteudo) === '') {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'O arquivo TXT está vazio.'
    ]);
}

$importador = new ImportadorTxt(
    $con,
    new ClassificadorServico(),
    new CalculadoraIss()
);

$resultado = $importador->processar($nomeOriginal, $conteudo);

responder(200, [
    'sucesso' => true,
    'mensagem' => 'Arquivo processado com sucesso.',
    'dados' => [
        'importacao_id' => (int)$resultado['importacaoId'],
        'arquivo' => $nomeOriginal,
        'status' => $resultado['status'],
        'total_linhas' => (int)$resultado['totalLinhas'],
        'total_processadas' => (int)$resultado['processadas'],
        'total_erros' => count($resultado['erros']),
        'iss_total' => round((float)$resultado['issTotal'], 2),
        'erros' => $resultado['erros']
    ]
]);

function responder(int $status, array $dados): never
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

function mensagemUpload(int $codigo): string
{
    return match ($codigo) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o tamanho permitido.',
        UPLOAD_ERR_PARTIAL => 'O upload do arquivo foi interrompido.',
        UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado.',
        UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não disponível.',
        UPLOAD_ERR_CANT_WRITE => 'Não foi possível gravar o arquivo temporário.',
        UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP interrompeu o upload.',
        default => 'Erro desconhecido no upload.'
    };
}
