<?php
declare(strict_types=1);

/*
 * API de cadastro de empresas (somente perfil ADMIN).
 * Endpoints:
 * GET    /src/api/empresas.php          lista paginada (filtros: busca, ativo)
 * GET    /src/api/empresas.php?id=N     detalhe
 * POST   /src/api/empresas.php          cria   {"cnpj", "razao_social", "ativo"?}
 * PUT    /src/api/empresas.php?id=N     altera só os campos enviados (inclusive "ativo": true para reativar)
 * DELETE /src/api/empresas.php?id=N     desativa (exclusão lógica: importações e notas são mantidas)
 */

require __DIR__ . '/requisicao.php';

iniciarEndpoint(['GET', 'POST', 'PUT', 'DELETE']);

require __DIR__ . '/../conexao.php';
require __DIR__ . '/../backend/Autenticacao.php';
require __DIR__ . '/../backend/Cadastro.php';
require __DIR__ . '/../backend/CadastroEmpresas.php';

$sessao = exigirAdmin($con);
$empresas = new CadastroEmpresas($con);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $id = lerIdUrl(false);

        if ($id !== null) {
            $empresa = $empresas->buscar($id);
            if ($empresa === null) {
                throw new ErroCadastro("Empresa $id não encontrada.", 404);
            }
            responder(200, ['sucesso' => true, 'dados' => $empresa]);
        }

        $filtros = [];
        if (lerTexto('busca') !== '') {
            $filtros['busca'] = lerTexto('busca');
        }
        if (($ativo = lerAtivoUrl()) !== null) {
            $filtros['ativo'] = $ativo;
        }
        [$pagina, $porPagina] = lerPaginacao();

        responder(200, ['sucesso' => true, 'dados' => $empresas->listar($filtros, $pagina, $porPagina)]);

    case 'POST':
        responder(201, [
            'sucesso' => true,
            'mensagem' => 'Empresa criada.',
            'dados' => $empresas->criar(lerCorpoJson())
        ]);

    case 'PUT':
        $id = lerIdUrl(true);
        $dados = lerCorpoJson();

        // O admin perderia o próprio acesso ao desativar a empresa dele.
        if ($id === $sessao['empresa']['id'] && $empresas->ativoInformado($dados) === false) {
            throw new ErroCadastro('não é possível desativar a empresa do próprio usuário logado.');
        }

        responder(200, [
            'sucesso' => true,
            'mensagem' => 'Empresa atualizada.',
            'dados' => $empresas->atualizar($id, $dados)
        ]);

    case 'DELETE':
        $id = lerIdUrl(true);

        if ($id === $sessao['empresa']['id']) {
            throw new ErroCadastro('não é possível desativar a empresa do próprio usuário logado.');
        }

        $empresas->desativar($id);

        responder(200, [
            'sucesso' => true,
            'mensagem' => "Empresa $id desativada. Para reativar, envie PUT com {\"ativo\": true}."
        ]);
}
