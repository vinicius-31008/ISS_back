<?php
declare(strict_types=1);

use OpenApi\Annotations as OA;

class HealthEndpointDocumentation
{
    /**
     * @OA\Get(
     *     path="/src/api/health.php",
     *     tags={"Sistema"},
     *     summary="Verifica se a API está online",
     *     description="Endpoint simples de healthcheck, sem dependência de banco de dados.",
     *     @OA\Response(
     *         response=200,
     *         description="API respondendo normalmente",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="sucesso", type="boolean", example=true),
     *             @OA\Property(property="servico", type="string", example="Importador Fiscal ISS API"),
     *             @OA\Property(property="status", type="string", example="online"),
     *             @OA\Property(property="versao", type="string", example="1.1.0")
     *         )
     *     ),
     *     @OA\Response(response=405, description="Método diferente de GET", @OA\JsonContent(ref="#/components/schemas/RespostaErro"))
     * )
     */
    public function health(): void
    {
    }
}

class ImportarEndpointDocumentation
{
    /**
     * @OA\Post(
     *     path="/src/api/importar.php",
     *     tags={"Importação"},
     *     summary="Importa um arquivo TXT de notas fiscais e calcula o ISS",
     *     description="Recebe um arquivo TXT (multipart/form-data), valida e processa linha a linha: classifica o serviço pelo código LC116, calcula o ISS devido e grava a importação, as notas e eventuais erros de linha no MySQL.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"arquivo"},
     *                 @OA\Property(property="arquivo", type="string", format="binary", description="Arquivo .txt com uma nota fiscal por linha, campos separados por ';'.")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Arquivo processado",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="sucesso", type="boolean", example=true),
     *             @OA\Property(property="mensagem", type="string", example="Arquivo processado com sucesso."),
     *             @OA\Property(property="dados", ref="#/components/schemas/ImportacaoResultado")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Requisição inválida", @OA\JsonContent(ref="#/components/schemas/RespostaErro")),
     *     @OA\Response(response=405, description="Método diferente de POST", @OA\JsonContent(ref="#/components/schemas/RespostaErro")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/RespostaErro"))
     * )
     */
    public function importar(): void
    {
    }
}
