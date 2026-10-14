<?php

/**
 * Erro de cadastro que o endpoint devolve ao cliente, com o status HTTP adequado
 * (400 dado inválido, 404 não encontrado, 409 duplicidade).
 */
class ErroCadastro extends RuntimeException
{
    public function __construct(string $mensagem, int $status = 400)
    {
        parent::__construct($mensagem, $status);
    }
}

/**
 * Base de CadastroEmpresas e CadastroUsuarios: leitura/validação dos campos do corpo
 * JSON e acesso ao banco.
 *
 * Convenção dos campos: ausente ou null = "não informado" (no PUT, mantém o valor atual).
 */
abstract class Cadastro
{
    protected mysqli $con;

    public function __construct(mysqli $con)
    {
        $this->con = $con;
    }

    /**
     * Rejeita campos desconhecidos: um erro de digitação ("razaosocial") não pode
     * passar como "nada a alterar".
     */
    protected function exigirCamposConhecidos(array $dados, array $permitidos): void
    {
        $desconhecidos = array_diff(array_keys($dados), $permitidos);

        if ($desconhecidos) {
            throw new ErroCadastro(
                'campo(s) não aceito(s): ' . implode(', ', $desconhecidos)
                . '. Aceitos: ' . implode(', ', $permitidos) . '.'
            );
        }
    }

    protected function lerTexto(array $dados, string $campo, int $tamanhoMaximo): ?string
    {
        if (!isset($dados[$campo])) {
            return null;
        }
        if (!is_string($dados[$campo])) {
            throw new ErroCadastro("$campo deve ser texto.");
        }

        $valor = trim($dados[$campo]);

        if ($valor === '') {
            throw new ErroCadastro("$campo não pode ser vazio.");
        }
        if (mb_strlen($valor) > $tamanhoMaximo) {
            throw new ErroCadastro("$campo deve ter no máximo $tamanhoMaximo caracteres.");
        }

        return $valor;
    }

    protected function lerCnpj(array $dados, string $campo): ?string
    {
        $valor = $this->lerTexto($dados, $campo, 18);
        if ($valor === null) {
            return null;
        }

        // Aceita com ou sem máscara (12.345.678/0001-90), grava só os dígitos.
        $digitos = preg_replace('/\D/', '', $valor);
        if (strlen($digitos) !== 14) {
            throw new ErroCadastro("$campo deve possuir 14 dígitos.");
        }

        return $digitos;
    }

    protected function lerId(array $dados, string $campo): ?int
    {
        if (!isset($dados[$campo])) {
            return null;
        }

        $valor = filter_var($dados[$campo], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($valor === false || is_bool($dados[$campo])) {
            throw new ErroCadastro("$campo deve ser um número inteiro maior ou igual a 1.");
        }

        return $valor;
    }

    protected function lerAtivo(array $dados): ?bool
    {
        if (!isset($dados['ativo'])) {
            return null;
        }
        if (!is_bool($dados['ativo'])) {
            throw new ErroCadastro('ativo deve ser true ou false.');
        }

        return $dados['ativo'];
    }

    protected function exigir($valor, string $campo)
    {
        if ($valor === null) {
            throw new ErroCadastro("$campo é obrigatório.");
        }

        return $valor;
    }

    /**
     * Monta o WHERE comum das listagens: busca textual em várias colunas + igualdades.
     * Devolve [where, tipos, params].
     */
    protected function montarFiltros(?string $busca, array $colunasBusca, array $igualdades): array
    {
        $condicoes = [];
        $tipos = '';
        $params = [];

        if ($busca !== null) {
            $termo = '%' . addcslashes($busca, '%_\\') . '%';
            $condicoes[] = '(' . implode(' OR ', array_map(function ($c) {
                return "$c LIKE ?";
            }, $colunasBusca)) . ')';
            $tipos .= str_repeat('s', count($colunasBusca));
            $params = array_merge($params, array_fill(0, count($colunasBusca), $termo));
        }

        foreach ($igualdades as $coluna => [$tipo, $valor]) {
            $condicoes[] = "$coluna = ?";
            $tipos .= $tipo;
            $params[] = $valor;
        }

        return [$condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '', $tipos, $params];
    }

    protected function paginacao(int $totalRegistros, int $pagina, int $porPagina): array
    {
        return [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $totalRegistros,
            'total_paginas' => (int)ceil($totalRegistros / $porPagina),
        ];
    }

    protected function consultar(string $sql, string $tipos, array $params): array
    {
        $stmt = mysqli_prepare($this->con, $sql);
        if ($tipos !== '') {
            mysqli_stmt_bind_param($stmt, $tipos, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $linhas = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        return $linhas;
    }

    /**
     * Executa INSERT/UPDATE/DELETE. Violação de chave única vira ErroCadastro 409
     * com $mensagemDuplicidade.
     */
    protected function executar(string $sql, string $tipos, array $params, string $mensagemDuplicidade = ''): void
    {
        $stmt = mysqli_prepare($this->con, $sql);
        if ($tipos !== '') {
            mysqli_stmt_bind_param($stmt, $tipos, ...$params);
        }

        try {
            mysqli_stmt_execute($stmt);
        } catch (mysqli_sql_exception $e) {
            // 1062 = chave única violada.
            if ($e->getCode() === 1062 && $mensagemDuplicidade !== '') {
                throw new ErroCadastro($mensagemDuplicidade, 409);
            }
            throw $e;
        } finally {
            mysqli_stmt_close($stmt);
        }
    }

    /**
     * UPDATE só dos campos informados. $campos = [coluna => [tipo, valor]].
     */
    protected function atualizarCampos(string $tabela, int $id, array $campos, string $mensagemDuplicidade): void
    {
        if (!$campos) {
            throw new ErroCadastro('nenhum campo para atualizar.');
        }

        $sets = array_map(function ($coluna) {
            return "$coluna = ?";
        }, array_keys($campos));

        $this->executar(
            "UPDATE $tabela SET " . implode(', ', $sets) . ' WHERE id = ?',
            implode('', array_column($campos, 0)) . 'i',
            array_merge(array_column($campos, 1), [$id]),
            $mensagemDuplicidade
        );
    }
}
