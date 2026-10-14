<?php
declare(strict_types=1);

/*
 * Funções comuns aos endpoints de cadastro (empresas.php, usuarios.php).
 */

/**
 * Cabeçalhos, CORS, tratamento de erros e métodos aceitos.
 * ErroCadastro lançado em qualquer ponto vira resposta com o status dele.
 */
function iniciarEndpoint(array $metodos): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . implode(', ', $metodos) . ', OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    set_exception_handler(function (Throwable $e): void {
        if ($e instanceof ErroCadastro) {
            responder($e->getCode(), [
                'sucesso' => false,
                'mensagem' => $e->getMessage()
            ]);
        }

        // Detalhes técnicos só no log: a mensagem da exceção pode conter SQL/estrutura do banco.
        error_log((string)$e);
        responder(500, [
            'sucesso' => false,
            'mensagem' => 'Erro interno no backend.'
        ]);
    });

    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if (!in_array($_SERVER['REQUEST_METHOD'], $metodos, true)) {
        responder(405, [
            'sucesso' => false,
            'mensagem' => 'Método não permitido. Utilize ' . implode(', ', $metodos) . '.'
        ]);
    }
}

/**
 * Sessão do usuário do token, exigindo perfil ADMIN (401 sem login, 403 sem permissão
 * ou com senha provisória ainda não trocada).
 */
function exigirAdmin(mysqli $con): array
{
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

    if ($sessao['usuario']['perfil'] !== 'ADMIN') {
        responder(403, [
            'sucesso' => false,
            'mensagem' => 'Acesso restrito a administradores.'
        ]);
    }

    return $sessao;
}

/** Corpo JSON da requisição (POST/PUT), como objeto. */
function lerCorpoJson(): array
{
    $corpo = json_decode((string)file_get_contents('php://input'), true);

    // Um objeto JSON ({...}) vira array associativo; lista, número ou texto não servem.
    if (!is_array($corpo) || ($corpo && array_keys($corpo) === range(0, count($corpo) - 1))) {
        erroParametro('o corpo deve ser um objeto JSON (Content-Type: application/json).');
    }

    return $corpo;
}

/** ?id= da URL, obrigatório para GET de um registro, PUT e DELETE. */
function lerIdUrl(bool $obrigatorio): ?int
{
    if (lerTexto('id') === '') {
        if ($obrigatorio) {
            erroParametro('informe o id do registro na URL (?id=N).');
        }
        return null;
    }

    return lerInteiro('id', 1, PHP_INT_MAX);
}

/** Filtro ?ativo=true|false da listagem; null se ausente. */
function lerAtivoUrl(): ?bool
{
    $texto = strtolower(lerTexto('ativo'));

    if ($texto === '') {
        return null;
    }
    if (!in_array($texto, ['true', 'false', '1', '0'], true)) {
        erroParametro('ativo deve ser true ou false.');
    }

    return in_array($texto, ['true', '1'], true);
}

/** [pagina, por_pagina] da URL: padrão 1 e 20, máximo 100 por página. */
function lerPaginacao(): array
{
    return [
        lerTexto('pagina') !== '' ? lerInteiro('pagina', 1, PHP_INT_MAX) : 1,
        lerTexto('por_pagina') !== '' ? lerInteiro('por_pagina', 1, 100) : 20,
    ];
}

function lerTexto(string $campo): string
{
    $valor = $_GET[$campo] ?? '';

    // ?campo[]=x chega como array.
    if (!is_string($valor)) {
        erroParametro("$campo inválido.");
    }

    return trim($valor);
}

function lerInteiro(string $campo, int $minimo, int $maximo): int
{
    $valor = filter_var(lerTexto($campo), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $minimo, 'max_range' => $maximo]
    ]);

    if ($valor === false) {
        $faixa = $maximo === PHP_INT_MAX ? "maior ou igual a $minimo" : "entre $minimo e $maximo";
        erroParametro("$campo deve ser um número inteiro $faixa.");
    }

    return $valor;
}

function erroParametro(string $mensagem): void
{
    responder(400, [
        'sucesso' => false,
        'mensagem' => $mensagem
    ]);
}

function responder(int $status, array $dados): void
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
