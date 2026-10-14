<?php
declare(strict_types=1);

/*
 * API de importação fiscal.
 * Endpoint:
 * POST /ImportadorFiscalISS/src/api/importar.php
 */

/**
 * @OA\Post(
 *     path="/src/api/importar.php",
 *     tags={"Importação"},
 *     summary="Importa um arquivo TXT de notas fiscais e calcula o ISS",
 *     description="Recebe um arquivo TXT (multipart/form-data), valida e processa linha a linha: classifica o serviço pelo código LC116, calcula o ISS devido e grava a importação, as notas e eventuais erros de linha no MySQL. Os registros ficam vinculados à empresa do usuário autenticado.",
 *     security={{"bearerAuth": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 required={"arquivo"},
 *                 @OA\Property(
 *                     property="arquivo",
 *                     type="string",
 *                     format="binary",
 *                     description="Arquivo .txt com uma nota fiscal por linha, campos separados por ';' (ver formato no README)."
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Arquivo processado (com ou sem erros de linha)",
 *         @OA\JsonContent(
 *             @OA\Property(property="sucesso", type="boolean", example=true),
 *             @OA\Property(property="mensagem", type="string", example="Arquivo processado com sucesso."),
 *             @OA\Property(property="dados", ref="#/components/schemas/ImportacaoResultado")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Requisição inválida (arquivo ausente, extensão incorreta, arquivo vazio, upload malformado)",
 *         @OA\JsonContent(ref="#/components/schemas/RespostaErro")
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Token ausente, inválido ou expirado",
 *         @OA\JsonContent(ref="#/components/schemas/RespostaErro")
 *     ),
 *     @OA\Response(
 *         response=405,
 *         description="Método diferente de POST",
 *         @OA\JsonContent(ref="#/components/schemas/RespostaErro")
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Erro interno no backend",
 *         @OA\JsonContent(
 *             @OA\Property(property="sucesso", type="boolean", example=false),
 *             @OA\Property(property="mensagem", type="string", example="Erro interno no backend."),
 *             @OA\Property(property="erro", type="string", example="mensagem técnica da exceção")
 *         )
 *     )
 * )
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
require __DIR__ . '/../backend/Autenticacao.php';
require __DIR__ . '/../backend/CalculadoraIss.php';
require __DIR__ . '/../backend/ClassificadorServico.php';
require __DIR__ . '/../backend/ImportadorTxt.php';

$sessao = (new Autenticacao($con))->usuarioDoToken(Autenticacao::tokenDaRequisicao());

if ($sessao === null) {
    responder(401, [
        'sucesso' => false,
        'mensagem' => 'Não autenticado. Faça login e envie o token no cabeçalho Authorization: Bearer <token>.'
    ]);
}

if ($sessao['usuario']['trocar_senha']) {
    responder(403, [
        'sucesso' => false,
        'mensagem' => Autenticacao::MENSAGEM_TROCAR_SENHA
    ]);
}

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

$resultado = $importador->processar(
    $sessao['empresa']['id'],
    $sessao['usuario']['id'],
    $nomeOriginal,
    $conteudo
);

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
    switch ($codigo) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'O arquivo excede o tamanho permitido.';
        case UPLOAD_ERR_PARTIAL:
            return 'O upload do arquivo foi interrompido.';
        case UPLOAD_ERR_NO_FILE:
            return 'Nenhum arquivo foi enviado.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Diretório temporário não disponível.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Não foi possível gravar o arquivo temporário.';
        case UPLOAD_ERR_EXTENSION:
            return 'Uma extensão do PHP interrompeu o upload.';
        default:
            return 'Erro desconhecido no upload.';
    }
}
