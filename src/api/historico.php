<?php
declare(strict_types=1);

/*
 * API de histórico das importações.
 * Endpoints:
 * GET /src/api/historico.php          lista paginada (filtros: status, arquivo, data_inicio, data_fim)
 * GET /src/api/historico.php?id=N     detalhe de uma importação, com notas e erros de linha
 *
 * Exige "Authorization: Bearer <token>" (ver login.php) e mostra só a empresa do usuário.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

set_exception_handler(function (Throwable $e): void {
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responder(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido. Utilize GET.'
    ]);
}

require __DIR__ . '/../conexao.php';
require __DIR__ . '/../backend/Autenticacao.php';
require __DIR__ . '/../backend/HistoricoImportacoes.php';

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

// Só as importações da empresa do usuário são visíveis.
$empresaId = $sessao['empresa']['id'];
$historico = new HistoricoImportacoes($con);

if (lerTexto('id') !== '') {
    $id = lerInteiro('id', 1, PHP_INT_MAX);
    $importacao = $historico->detalhar($empresaId, $id);

    // Importação de outra empresa responde como inexistente (404), sem revelar que existe.
    if ($importacao === null) {
        responder(404, [
            'sucesso' => false,
            'mensagem' => "Importação $id não encontrada."
        ]);
    }

    responder(200, [
        'sucesso' => true,
        'dados' => $importacao
    ]);
}

$filtros = [];

if (lerTexto('status') !== '') {
    $status = strtoupper(lerTexto('status'));
    if (!in_array($status, HistoricoImportacoes::STATUS_VALIDOS, true)) {
        erroParametro('status deve ser um destes: ' . implode(', ', HistoricoImportacoes::STATUS_VALIDOS) . '.');
    }
    $filtros['status'] = $status;
}

if (lerTexto('arquivo') !== '') {
    $filtros['arquivo'] = lerTexto('arquivo');
}

foreach (['data_inicio', 'data_fim'] as $campo) {
    if (lerTexto($campo) !== '') {
        $filtros[$campo] = lerData($campo);
    }
}

// Datas em YYYY-MM-DD: a comparação de strings equivale à cronológica.
if (isset($filtros['data_inicio'], $filtros['data_fim']) && $filtros['data_inicio'] > $filtros['data_fim']) {
    erroParametro('data_inicio não pode ser maior que data_fim.');
}

$pagina = lerTexto('pagina') !== '' ? lerInteiro('pagina', 1, PHP_INT_MAX) : 1;
$porPagina = lerTexto('por_pagina') !== '' ? lerInteiro('por_pagina', 1, 100) : 20;

responder(200, [
    'sucesso' => true,
    'dados' => $historico->listar($empresaId, $filtros, $pagina, $porPagina)
]);

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

function lerData(string $campo): string
{
    $texto = lerTexto($campo);
    $data = DateTime::createFromFormat('!Y-m-d', $texto);

    if (!$data || $data->format('Y-m-d') !== $texto) {
        erroParametro("$campo deve ser uma data válida no formato YYYY-MM-DD.");
    }

    return $texto;
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
