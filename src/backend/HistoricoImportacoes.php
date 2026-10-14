<?php

class HistoricoImportacoes
{
    public const STATUS_VALIDOS = ['PROCESSADA', 'PROCESSADA_COM_ERROS', 'ERRO'];

    private mysqli $con;

    public function __construct(mysqli $con)
    {
        $this->con = $con;
    }

    private const CAMPOS_IMPORTACAO =
        'i.id, i.arquivo, i.data_importacao, i.total_linhas, i.total_processadas, i.total_erros, i.iss_total, i.status,
         u.id AS usuario_id, u.nome AS usuario_nome';

    /**
     * Lista as importações da empresa, mais recentes primeiro.
     *
     * Filtros aceitos (todos opcionais, já validados pelo endpoint):
     * status, arquivo (trecho do nome), data_inicio e data_fim (YYYY-MM-DD, inclusivas).
     */
    public function listar(int $empresaId, array $filtros, int $pagina, int $porPagina): array
    {
        $condicoes = ['i.empresa_id = ?'];
        $tipos = 'i';
        $params = [$empresaId];

        if (isset($filtros['status'])) {
            $condicoes[] = 'i.status = ?';
            $tipos .= 's';
            $params[] = $filtros['status'];
        }
        if (isset($filtros['arquivo'])) {
            $condicoes[] = 'i.arquivo LIKE ?';
            $tipos .= 's';
            $params[] = '%' . addcslashes($filtros['arquivo'], '%_\\') . '%';
        }
        if (isset($filtros['data_inicio'])) {
            $condicoes[] = 'i.data_importacao >= ?';
            $tipos .= 's';
            $params[] = $filtros['data_inicio'] . ' 00:00:00';
        }
        if (isset($filtros['data_fim'])) {
            $condicoes[] = 'i.data_importacao < DATE_ADD(?, INTERVAL 1 DAY)';
            $tipos .= 's';
            $params[] = $filtros['data_fim'];
        }

        $where = 'WHERE ' . implode(' AND ', $condicoes);

        $resumo = $this->consultar(
            "SELECT COUNT(*) AS total, COALESCE(SUM(i.iss_total), 0) AS iss_total FROM importacoes i $where",
            $tipos,
            $params
        )[0];

        $importacoes = $this->consultar(
            'SELECT ' . self::CAMPOS_IMPORTACAO . "
             FROM importacoes i
             LEFT JOIN usuarios u ON u.id = i.usuario_id
             $where
             ORDER BY i.data_importacao DESC, i.id DESC
             LIMIT ? OFFSET ?",
            $tipos . 'ii',
            array_merge($params, [$porPagina, ($pagina - 1) * $porPagina])
        );

        $totalRegistros = (int)$resumo['total'];

        return [
            'importacoes' => array_map([$this, 'formatarImportacao'], $importacoes),
            'paginacao' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $totalRegistros,
                'total_paginas' => (int)ceil($totalRegistros / $porPagina),
            ],
            'resumo' => [
                'iss_total' => round((float)$resumo['iss_total'], 2),
            ],
        ];
    }

    /**
     * Detalhe de uma importação: totais, notas e erros de linha. Null se não existir
     * ou se pertencer a outra empresa.
     *
     * As notas listadas são as que ainda pertencem à importação: uma nota reimportada
     * depois passa a apontar para a importação mais recente.
     */
    public function detalhar(int $empresaId, int $id): ?array
    {
        $importacao = $this->consultar(
            'SELECT ' . self::CAMPOS_IMPORTACAO . '
             FROM importacoes i
             LEFT JOIN usuarios u ON u.id = i.usuario_id
             WHERE i.id = ? AND i.empresa_id = ?',
            'ii',
            [$id, $empresaId]
        );

        if (!$importacao) {
            return null;
        }

        $notas = $this->consultar(
            'SELECT n.id, n.numero, n.serie, n.data_emissao,
                    p.cnpj AS prestador_cnpj, p.razao_social AS prestador_razao_social,
                    t.cpf_cnpj AS tomador_documento, t.razao_social AS tomador_razao_social,
                    s.codigo_lc116, s.descricao AS servico_descricao,
                    n.municipio_prestacao, n.valor_servico, n.base_calculo, n.aliquota_iss, n.valor_iss, n.status
             FROM notas n
             JOIN prestadores p ON p.id = n.prestador_id
             LEFT JOIN tomadores t ON t.id = n.tomador_id
             JOIN servicos s ON s.id = n.servico_id
             WHERE n.importacao_id = ?
             ORDER BY n.id',
            'i',
            [$id]
        );

        $erros = $this->consultar(
            'SELECT numero_linha, mensagem, linha_original
             FROM erros_importacao
             WHERE importacao_id = ?
             ORDER BY numero_linha',
            'i',
            [$id]
        );

        return $this->formatarImportacao($importacao[0]) + [
            'notas' => array_map([$this, 'formatarNota'], $notas),
            'erros' => array_map([$this, 'formatarErro'], $erros),
        ];
    }

    private function consultar(string $sql, string $tipos, array $params): array
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

    private function formatarImportacao(array $i): array
    {
        return [
            'id' => (int)$i['id'],
            'arquivo' => $i['arquivo'],
            'data_importacao' => $i['data_importacao'],
            'status' => $i['status'],
            'total_linhas' => (int)$i['total_linhas'],
            'total_processadas' => (int)$i['total_processadas'],
            'total_erros' => (int)$i['total_erros'],
            'iss_total' => round((float)$i['iss_total'], 2),
            // Null nas importações anteriores ao cadastro de usuários ou se o usuário foi excluído.
            'usuario' => $i['usuario_id'] === null ? null : [
                'id' => (int)$i['usuario_id'],
                'nome' => $i['usuario_nome'],
            ],
        ];
    }

    private function formatarNota(array $n): array
    {
        return [
            'id' => (int)$n['id'],
            'numero' => $n['numero'],
            'serie' => $n['serie'],
            'data_emissao' => $n['data_emissao'],
            'prestador' => [
                'cnpj' => $n['prestador_cnpj'],
                'razao_social' => $n['prestador_razao_social'],
            ],
            'tomador' => $n['tomador_documento'] === null ? null : [
                'cpf_cnpj' => $n['tomador_documento'],
                'razao_social' => $n['tomador_razao_social'],
            ],
            'servico' => [
                'codigo_lc116' => $n['codigo_lc116'],
                'descricao' => $n['servico_descricao'],
            ],
            'municipio_prestacao' => $n['municipio_prestacao'],
            'valor_servico' => round((float)$n['valor_servico'], 2),
            'base_calculo' => round((float)$n['base_calculo'], 2),
            'aliquota_iss' => (float)$n['aliquota_iss'],
            'valor_iss' => round((float)$n['valor_iss'], 2),
            'status' => $n['status'],
        ];
    }

    private function formatarErro(array $e): array
    {
        // Mesmo formato dos erros devolvidos por importar.php.
        return [
            'linha' => (int)$e['numero_linha'],
            'mensagem' => $e['mensagem'],
            'original' => $e['linha_original'],
        ];
    }
}
