<?php
declare(strict_types=1);

/*
 * Exportação de uma nota para a declaração de ISS de Santa Bárbara d'Oeste/SP (SIGISSWEB).
 * Endpoint:
 * GET /src/api/exportar-iss.php?id=N   N = id da nota
 *     &regime=S|V|M|L                  regime de apuração do prestador (padrão V)
 *     &retencao=N|S|F|D|I              retenção do ISS (padrão N)
 *     &homologacao=true                registro 0 com "X": o SIGISSWEB só valida o arquivo
 *
 * Responde o TXT (ANSI) como download; erros continuam em JSON.
 * Exige "Authorization: Bearer <token>" (ver login.php) e só exporta notas da empresa do usuário.
 */

require __DIR__ . '/requisicao.php';

iniciarEndpoint(['GET']);

require __DIR__ . '/../conexao.php';
require __DIR__ . '/../backend/Autenticacao.php';
require __DIR__ . '/../backend/Cadastro.php';
require __DIR__ . '/../backend/ExportadorIssSantaBarbara.php';

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

$id = lerIdUrl(true);
$regime = lerOpcao('regime', ExportadorIssSantaBarbara::REGIMES, 'V');
$retencao = lerOpcao('retencao', ExportadorIssSantaBarbara::RETENCOES, 'N');
$homologacao = in_array(strtolower(lerTexto('homologacao')), ['true', '1'], true);

$exportacao = (new ExportadorIssSantaBarbara($con))
    ->exportarNota($sessao['empresa']['id'], $id, $regime, $retencao, $homologacao);

// Nota de outra empresa responde como inexistente (404), sem revelar que existe.
if ($exportacao === null) {
    responder(404, [
        'sucesso' => false,
        'mensagem' => "Nota $id não encontrada."
    ]);
}

header('Content-Type: text/plain; charset=windows-1252');
header('Content-Disposition: attachment; filename="' . $exportacao['arquivo'] . '"');
// Sem isso o front (outra origem) não consegue ler o nome do arquivo.
header('Access-Control-Expose-Headers: Content-Disposition');
echo $exportacao['conteudo'];

/** Letra única de uma lista de valores aceitos, com padrão quando ausente. */
function lerOpcao(string $campo, array $validos, string $padrao): string
{
    $valor = strtoupper(lerTexto($campo));

    if ($valor === '') {
        return $padrao;
    }
    if (!in_array($valor, $validos, true)) {
        erroParametro("$campo deve ser um destes: " . implode(', ', $validos) . '.');
    }

    return $valor;
}
