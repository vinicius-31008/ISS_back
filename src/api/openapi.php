<?php
declare(strict_types=1);

use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="Importador Fiscal ISS — API",
 *     version="1.1.0",
 *     description="API para upload de arquivos TXT de notas fiscais, classificação do serviço prestado (LC 116), cálculo do ISS devido e persistência no MySQL."
 * )
 * @OA\Server(url="http://localhost:8000", description="Ambiente local")
 * @OA\Tag(name="Sistema", description="Endpoints de status/infraestrutura da API")
 * @OA\Tag(name="Importação", description="Endpoints de importação e processamento fiscal do arquivo TXT")
 * @OA\Schema(
 *     schema="ErroLinha",
 *     type="object",
 *     @OA\Property(property="linha", type="integer", example=3, description="Número da linha no arquivo TXT (1-based)"),
 *     @OA\Property(property="mensagem", type="string", example="serviço 99.99 não cadastrado."),
 *     @OA\Property(property="original", type="string", example="1003;A;2026-08-20;...;99.99;3550407;10000,00")
 * )
 * @OA\Schema(
 *     schema="ImportacaoResultado",
 *     type="object",
 *     @OA\Property(property="importacao_id", type="integer", example=1),
 *     @OA\Property(property="arquivo", type="string", example="notas_teste_iss.txt"),
 *     @OA\Property(property="status", type="string", enum={"PROCESSADA", "PROCESSADA_COM_ERROS"}, example="PROCESSADA"),
 *     @OA\Property(property="total_linhas", type="integer", example=5),
 *     @OA\Property(property="total_processadas", type="integer", example=5),
 *     @OA\Property(property="total_erros", type="integer", example=0),
 *     @OA\Property(property="iss_total", type="number", format="float", example=1537.54),
 *     @OA\Property(property="erros", type="array", @OA\Items(ref="#/components/schemas/ErroLinha"))
 * )
 * @OA\Schema(
 *     schema="RespostaErro",
 *     type="object",
 *     @OA\Property(property="sucesso", type="boolean", example=false),
 *     @OA\Property(property="mensagem", type="string", example="Apenas arquivos .txt são aceitos."),
 *     @OA\Property(property="erro", type="string", example="mensagem técnica da exceção")
 * )
 */
class OpenApiDocumentation
{
}
