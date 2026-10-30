<?php
declare(strict_types=1);

/*
 * API do painel inicial (indicadores e séries para gráficos).
 * Endpoint:
 * GET /src/api/dashboard.php?meses=N   N = meses da série mensal (1 a 36, padrão 12)
 *
 * Exige "Authorization: Bearer <token>" (ver login.php) e mostra só a empresa do usuário.
 */

require __DIR__ . '/requisicao.php';

iniciarEndpoint(['GET']);

require __DIR__ . '/../conexao.php';
require __DIR__ . '/../backend/Autenticacao.php';
require __DIR__ . '/../backend/HistoricoImportacoes.php';
require __DIR__ . '/../backend/Dashboard.php';

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

$meses = lerTexto('meses') !== '' ? lerInteiro('meses', 1, 36) : 12;

responder(200, [
    'sucesso' => true,
    'dados' => (new Dashboard($con))->resumo($sessao['empresa']['id'], $meses)
]);
