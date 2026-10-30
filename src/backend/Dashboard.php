<?php

class Dashboard
{
    private mysqli $con;

    public function __construct(mysqli $con)
    {
        $this->con = $con;
    }

    /**
     * Indicadores da empresa para o painel inicial.
     *
     * As notas pertencem à empresa por meio do prestador (prestadores.empresa_id).
     * A série mensal cobre os últimos $meses meses (incluindo o atual) pela data de
     * emissão da nota; meses sem notas aparecem zerados para o gráfico não ter buracos.
     */
    public function resumo(int $empresaId, int $meses): array
    {
        $notas = $this->consultar(
            'SELECT COUNT(*) AS total, COALESCE(SUM(n.valor_servico), 0) AS valor_servico,
                    COALESCE(SUM(n.valor_iss), 0) AS valor_iss
             FROM notas n
             JOIN prestadores p ON p.id = n.prestador_id
             WHERE p.empresa_id = ?',
            'i',
            [$empresaId]
        )[0];

        $importacoes = $this->consultar(
            'SELECT COUNT(*) AS total, COALESCE(SUM(total_erros), 0) AS erros
             FROM importacoes
             WHERE empresa_id = ?',
            'i',
            [$empresaId]
        )[0];

        $cadastros = $this->consultar(
            'SELECT (SELECT COUNT(*) FROM prestadores WHERE empresa_id = ?) AS prestadores,
                    (SELECT COUNT(*) FROM tomadores WHERE empresa_id = ?) AS tomadores',
            'ii',
            [$empresaId, $empresaId]
        )[0];

        return [
            'totais' => [
                'notas' => (int)$notas['total'],
                'valor_servico' => round((float)$notas['valor_servico'], 2),
                'valor_iss' => round((float)$notas['valor_iss'], 2),
                'importacoes' => (int)$importacoes['total'],
                'linhas_com_erro' => (int)$importacoes['erros'],
                'prestadores' => (int)$cadastros['prestadores'],
                'tomadores' => (int)$cadastros['tomadores'],
            ],
            'por_mes' => $this->porMes($empresaId, $meses),
            'importacoes_por_status' => $this->importacoesPorStatus($empresaId),
            'top_servicos' => $this->topServicos($empresaId),
            'top_tomadores' => $this->topTomadores($empresaId),
            'ultimas_importacoes' => $this->ultimasImportacoes($empresaId),
        ];
    }

    private function porMes(int $empresaId, int $meses): array
    {
        $inicio = new DateTime('first day of this month 00:00:00');
        $inicio->modify('-' . ($meses - 1) . ' months');

        $linhas = $this->consultar(
            "SELECT DATE_FORMAT(n.data_emissao, '%Y-%m') AS mes, COUNT(*) AS notas,
                    SUM(n.valor_servico) AS valor_servico, SUM(n.valor_iss) AS valor_iss
             FROM notas n
             JOIN prestadores p ON p.id = n.prestador_id
             WHERE p.empresa_id = ? AND n.data_emissao >= ?
             GROUP BY mes",
            'is',
            [$empresaId, $inicio->format('Y-m-d H:i:s')]
        );
        $porMes = array_column($linhas, null, 'mes');

        $serie = [];
        for ($i = 0; $i < $meses; $i++) {
            $mes = $inicio->format('Y-m');
            $linha = $porMes[$mes] ?? null;
            $serie[] = [
                'mes' => $mes,
                'notas' => (int)($linha['notas'] ?? 0),
                'valor_servico' => round((float)($linha['valor_servico'] ?? 0), 2),
                'valor_iss' => round((float)($linha['valor_iss'] ?? 0), 2),
            ];
            $inicio->modify('+1 month');
        }

        return $serie;
    }

    private function importacoesPorStatus(int $empresaId): array
    {
        $linhas = $this->consultar(
            'SELECT status, COUNT(*) AS total FROM importacoes WHERE empresa_id = ? GROUP BY status',
            'i',
            [$empresaId]
        );
        $totais = array_column($linhas, 'total', 'status');

        // Sempre os três status, na mesma ordem, para o gráfico manter as cores.
        return array_map(
            fn(string $status): array => ['status' => $status, 'total' => (int)($totais[$status] ?? 0)],
            HistoricoImportacoes::STATUS_VALIDOS
        );
    }

    private function topServicos(int $empresaId): array
    {
        $linhas = $this->consultar(
            'SELECT s.codigo_lc116, s.descricao, COUNT(*) AS notas, SUM(n.valor_iss) AS valor_iss
             FROM notas n
             JOIN prestadores p ON p.id = n.prestador_id
             JOIN servicos s ON s.id = n.servico_id
             WHERE p.empresa_id = ?
             GROUP BY s.id, s.codigo_lc116, s.descricao
             ORDER BY valor_iss DESC
             LIMIT 5',
            'i',
            [$empresaId]
        );

        return array_map(fn(array $s): array => [
            'codigo_lc116' => $s['codigo_lc116'],
            'descricao' => $s['descricao'],
            'notas' => (int)$s['notas'],
            'valor_iss' => round((float)$s['valor_iss'], 2),
        ], $linhas);
    }

    private function topTomadores(int $empresaId): array
    {
        $linhas = $this->consultar(
            'SELECT t.cpf_cnpj, t.razao_social, COUNT(*) AS notas,
                    SUM(n.valor_servico) AS valor_servico, SUM(n.valor_iss) AS valor_iss
             FROM notas n
             JOIN prestadores p ON p.id = n.prestador_id
             JOIN tomadores t ON t.id = n.tomador_id
             WHERE p.empresa_id = ?
             GROUP BY t.id, t.cpf_cnpj, t.razao_social
             ORDER BY valor_servico DESC
             LIMIT 5',
            'i',
            [$empresaId]
        );

        return array_map(fn(array $t): array => [
            'cpf_cnpj' => $t['cpf_cnpj'],
            'razao_social' => $t['razao_social'],
            'notas' => (int)$t['notas'],
            'valor_servico' => round((float)$t['valor_servico'], 2),
            'valor_iss' => round((float)$t['valor_iss'], 2),
        ], $linhas);
    }

    private function ultimasImportacoes(int $empresaId): array
    {
        $linhas = $this->consultar(
            'SELECT id, arquivo, data_importacao, status, total_processadas, total_erros, iss_total
             FROM importacoes
             WHERE empresa_id = ?
             ORDER BY data_importacao DESC, id DESC
             LIMIT 5',
            'i',
            [$empresaId]
        );

        return array_map(fn(array $i): array => [
            'id' => (int)$i['id'],
            'arquivo' => $i['arquivo'],
            'data_importacao' => $i['data_importacao'],
            'status' => $i['status'],
            'total_processadas' => (int)$i['total_processadas'],
            'total_erros' => (int)$i['total_erros'],
            'iss_total' => round((float)$i['iss_total'], 2),
        ], $linhas);
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
}
