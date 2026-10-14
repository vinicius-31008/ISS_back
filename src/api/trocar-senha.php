<?php
declare(strict_types=1);

/*
 * API de troca da própria senha.
 * Endpoint:
 * POST /src/api/trocar-senha.php   {"senha_atual": "...", "nova_senha": "..."}
 *
 * Exige "Authorization: Bearer <token>". É a única rota liberada enquanto o usuário
 * está com senha provisória (trocar_senha = true, ex.: o administrador padrão).
 * Mantém a sessão atual e encerra as demais sessões do usuário.
 */

require __DIR__ . '/requisicao.php';

iniciarEndpoint(['POST']);

require __DIR__ . '/../conexao.php';
require __DIR__ . '/../backend/Autenticacao.php';
require __DIR__ . '/../backend/Cadastro.php';
require __DIR__ . '/../backend/CadastroUsuarios.php';

$token = Autenticacao::tokenDaRequisicao();
$autenticacao = new Autenticacao($con);
$sessao = $autenticacao->usuarioDoToken($token);

if ($sessao === null) {
    responder(401, [
        'sucesso' => false,
        'mensagem' => 'Não autenticado. Faça login e envie o token no cabeçalho Authorization: Bearer <token>.'
    ]);
}

$dados = lerCorpoJson();
$senhaAtual = $dados['senha_atual'] ?? null;
$novaSenha = $dados['nova_senha'] ?? null;

if (!is_string($senhaAtual) || !is_string($novaSenha) || $senhaAtual === '' || $novaSenha === '') {
    erroParametro('informe senha_atual e nova_senha.');
}

CadastroUsuarios::validarSenha($novaSenha, 'nova_senha');

if ($novaSenha === $senhaAtual) {
    erroParametro('nova_senha deve ser diferente da senha atual.');
}

if (!$autenticacao->trocarSenha($sessao['usuario']['id'], $senhaAtual, $novaSenha, $token)) {
    erroParametro('senha_atual incorreta.');
}

responder(200, [
    'sucesso' => true,
    'mensagem' => 'Senha alterada. As demais sessões deste usuário foram encerradas.'
]);
