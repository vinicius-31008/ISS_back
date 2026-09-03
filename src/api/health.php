<?php
declare(strict_types=1);

/**
 * @OA\Get(
 *     path="/src/api/health.php",
 *     tags={"Sistema"},
 *     summary="Verifica se a API está online",
 *     description="Endpoint simples de healthcheck, sem dependência de banco de dados. Usado pelo frontend para testar a conexão antes de enviar o arquivo.",
 *     @OA\Response(
 *         response=200,
 *         description="API respondendo normalmente",
 *         @OA\JsonContent(
 *             @OA\Property(property="sucesso", type="boolean", example=true),
 *             @OA\Property(property="servico", type="string", example="Importador Fiscal ISS API"),
 *             @OA\Property(property="status", type="string", example="online"),
 *             @OA\Property(property="versao", type="string", example="1.1.0")
 *         )
 *     ),
 *     @OA\Response(
 *         response=405,
 *         description="Método diferente de GET",
 *         @OA\JsonContent(ref="#/components/schemas/RespostaErro")
 *     )
 * )
 */

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
