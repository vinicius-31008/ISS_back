<?php
declare(strict_types=1);

/*
 * Exportação em lote de notas para a declaração de ISS de Santa Bárbara d'Oeste/SP (SIGISSWEB).
 * Endpoint:
 * POST /src/api/exportar-iss-lote.php
 * Corpo JSON:
 *   {
 *     "ids": [1, 2, 3],          ids das notas (1 a 500, sem repetição obrigatória)
 *     "regime": "V",             S|V|M|L (padrão V)
 *     "retencao": "N",           N|S|F|D|I (padrão N)
 *     "homologacao": false       registro 0 com "X": o SIGISSWEB só valida o arquivo
 *   }
 *
 * As notas são agrupadas por prestador + competência (um TXT por grupo, como exige o layout).
 * Um grupo só → responde o TXT (ANSI); vários → responde um ZIP com todos os TXT.
 * Cabeçalhos X-Notas-Exportadas e X-Arquivos-Gerados informam o resumo.
 *
 * Se qualquer nota não existir ou não couber no layout, nada é gerado: responde 422 com
 * "erros": [{ "id", "numero", "mensagem" }] para o usuário ajustar a seleção.
 * Exige "Authorization: Bearer <token>" (ver login.php) e só exporta notas da empresa do usuário.
 */

require __DIR__ . '/requisicao.php';

iniciarEndpoint(['POST']);

require __DIR__ . '/../conexao.php';
require __DIR__ . '/../backend/Autenticacao.php';
require __DIR__ . '/../backend/Cadastro.php';
require __DIR__ . '/../backend/ZipSimples.php';
require __DIR__ . '/../backend/ExportadorIssSantaBarbara.php';

const MAXIMO_NOTAS_LOTE = 500;

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

$corpo = lerCorpoJson();
$ids = lerIdsLote($corpo['ids'] ?? null);
$regime = lerOpcaoCorpo($corpo, 'regime', ExportadorIssSantaBarbara::REGIMES, 'V');
$retencao = lerOpcaoCorpo($corpo, 'retencao', ExportadorIssSantaBarbara::RETENCOES, 'N');
$homologacao = lerBooleanoCorpo($corpo, 'homologacao');

$exportacao = (new ExportadorIssSantaBarbara($con))
    ->exportarLote($sessao['empresa']['id'], $ids, $regime, $retencao, $homologacao);

if (isset($exportacao['erros'])) {
    $quantidade = count($exportacao['erros']);
    responder(422, [
        'sucesso' => false,
        'mensagem' => $quantidade === 1
            ? '1 nota não pode ser exportada. Corrija ou remova da seleção.'
            : "$quantidade notas não podem ser exportadas. Corrija ou remova da seleção.",
        'erros' => $exportacao['erros']
    ]);
}

header('Content-Type: ' . $exportacao['tipo']);
header('Content-Disposition: attachment; filename="' . $exportacao['arquivo'] . '"');
header('Content-Length: ' . strlen($exportacao['conteudo']));
header('X-Notas-Exportadas: ' . $exportacao['notas']);
header('X-Arquivos-Gerados: ' . $exportacao['arquivos']);
// Sem isso o front (outra origem) não consegue ler esses cabeçalhos.
header('Access-Control-Expose-Headers: Content-Disposition, X-Notas-Exportadas, X-Arquivos-Gerados');
echo $exportacao['conteudo'];

/** Lista de ids de nota (inteiros ≥ 1), de 1 a MAXIMO_NOTAS_LOTE itens. */
function lerIdsLote(mixed $valor): array
{
    if (!is_array($valor) || $valor === [] || array_keys($valor) !== range(0, count($valor) - 1)) {
        erroParametro('ids deve ser uma lista com pelo menos um id de nota.');
    }

    $ids = [];
    foreach ($valor as $id) {
        if (!is_int($id) || $id < 1) {
            erroParametro('ids deve conter apenas números inteiros maiores ou iguais a 1.');
        }
        $ids[] = $id;
    }

    $ids = array_values(array_unique($ids));
    if (count($ids) > MAXIMO_NOTAS_LOTE) {
        erroParametro('exporte no máximo ' . MAXIMO_NOTAS_LOTE . ' notas por lote.');
    }

    return $ids;
}

/** Letra única de uma lista de valores aceitos, com padrão quando ausente. */
function lerOpcaoCorpo(array $corpo, string $campo, array $validos, string $padrao): string
{
    $valor = $corpo[$campo] ?? null;

    if ($valor === null || $valor === '') {
        return $padrao;
    }
    if (!is_string($valor) || !in_array(strtoupper($valor), $validos, true)) {
        erroParametro("$campo deve ser um destes: " . implode(', ', $validos) . '.');
    }

    return strtoupper($valor);
}

function lerBooleanoCorpo(array $corpo, string $campo): bool
{
    $valor = $corpo[$campo] ?? false;

    if (!is_bool($valor)) {
        erroParametro("$campo deve ser true ou false.");
    }

    return $valor;
}
