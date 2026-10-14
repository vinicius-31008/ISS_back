<?php
declare(strict_types=1);

/*
 * API de cadastro de usuários (somente perfil ADMIN).
 * Endpoints:
 * GET    /src/api/usuarios.php          lista paginada (filtros: busca, empresa_id, perfil, ativo)
 * GET    /src/api/usuarios.php?id=N     detalhe
 * POST   /src/api/usuarios.php          cria   {"empresa_id", "nome", "email", "senha", "perfil"?, "ativo"?}
 * PUT    /src/api/usuarios.php?id=N     altera só os campos enviados (trocar senha encerra as sessões do usuário)
 * DELETE /src/api/usuarios.php?id=N     desativa (exclusão lógica: o histórico guarda quem importou)
 *
 * A senha nunca é devolvida, nem como hash.
 */

require __DIR__ . '/requisicao.php';

iniciarEndpoint(['GET', 'POST', 'PUT', 'DELETE']);

require __DIR__ . '/../conexao.php';
require __DIR__ . '/../backend/Autenticacao.php';
require __DIR__ . '/../backend/Cadastro.php';
require __DIR__ . '/../backend/CadastroUsuarios.php';

$sessao = exigirAdmin($con);
$usuarios = new CadastroUsuarios($con);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $id = lerIdUrl(false);

        if ($id !== null) {
            $usuario = $usuarios->buscar($id);
            if ($usuario === null) {
                throw new ErroCadastro("Usuário $id não encontrado.", 404);
            }
            responder(200, ['sucesso' => true, 'dados' => $usuario]);
        }

        $filtros = [];
        if (lerTexto('busca') !== '') {
            $filtros['busca'] = lerTexto('busca');
        }
        if (lerTexto('empresa_id') !== '') {
            $filtros['empresa_id'] = lerInteiro('empresa_id', 1, PHP_INT_MAX);
        }
        if (lerTexto('perfil') !== '') {
            $perfil = strtoupper(lerTexto('perfil'));
            if (!in_array($perfil, CadastroUsuarios::PERFIS, true)) {
                erroParametro('perfil deve ser um destes: ' . implode(', ', CadastroUsuarios::PERFIS) . '.');
            }
            $filtros['perfil'] = $perfil;
        }
        if (($ativo = lerAtivoUrl()) !== null) {
            $filtros['ativo'] = $ativo;
        }
        [$pagina, $porPagina] = lerPaginacao();

        responder(200, ['sucesso' => true, 'dados' => $usuarios->listar($filtros, $pagina, $porPagina)]);

    case 'POST':
        responder(201, [
            'sucesso' => true,
            'mensagem' => 'Usuário criado.',
            'dados' => $usuarios->criar(lerCorpoJson())
        ]);

    case 'PUT':
        $id = lerIdUrl(true);
        $dados = lerCorpoJson();

        // Sem isso o admin poderia se trancar para fora (e o sistema ficar sem nenhum admin).
        if ($id === $sessao['usuario']['id']) {
            if ($usuarios->ativoInformado($dados) === false) {
                throw new ErroCadastro('não é possível desativar o próprio usuário.');
            }
            $perfil = $usuarios->perfilInformado($dados);
            if ($perfil !== null && $perfil !== 'ADMIN') {
                throw new ErroCadastro('não é possível remover o próprio perfil ADMIN.');
            }
        }

        responder(200, [
            'sucesso' => true,
            'mensagem' => 'Usuário atualizado.',
            'dados' => $usuarios->atualizar($id, $dados)
        ]);

    case 'DELETE':
        $id = lerIdUrl(true);

        if ($id === $sessao['usuario']['id']) {
            throw new ErroCadastro('não é possível desativar o próprio usuário.');
        }

        $usuarios->desativar($id);

        responder(200, [
            'sucesso' => true,
            'mensagem' => "Usuário $id desativado. Para reativar, envie PUT com {\"ativo\": true}."
        ]);
}
