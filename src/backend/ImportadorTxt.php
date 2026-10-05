<?php

class ImportadorTxt
{
    private mysqli $con;
    private ClassificadorServico $classificador;
    private CalculadoraIss $calculadora;

    public function __construct(mysqli $con, ClassificadorServico $classificador, CalculadoraIss $calculadora)
    {
        $this->con = $con;
        $this->classificador = $classificador;
        $this->calculadora = $calculadora;
    }

    public function processar(string $nomeArquivo, string $conteudo): array
    {
        $linhas = preg_split('/\r\n|\r|\n/', $conteudo);
        $totalLinhas = 0;
        $processadas = 0;
        $erros = [];
        $issTotal = 0.0;
        // prestador|numero|serie => linha em que a nota já foi gravada neste arquivo
        $notasDoArquivo = [];

        mysqli_begin_transaction($this->con);

        try {
            $importacaoId = $this->criarImportacao($nomeArquivo);

            foreach ($linhas as $indice => $linhaOriginal) {
                $linha = trim($linhaOriginal);
                if ($linha === '') {
                    continue;
                }

                $totalLinhas++;
                $numeroLinha = $indice + 1;

                // Savepoint por linha: se a linha falhar no meio, nada dela fica gravado
                // (ex.: prestador inserido mas nota rejeitada).
                mysqli_query($this->con, 'SAVEPOINT linha');

                try {
                    $dados = $this->lerLinha($linha);

                    // Minúsculas: a collation do banco não diferencia "A" de "a" na chave única.
                    $chaveNota = strtolower($dados['cnpj_prestador'] . '|' . $dados['numero'] . '|' . $dados['serie']);
                    if (isset($notasDoArquivo[$chaveNota])) {
                        throw new InvalidArgumentException(
                            "nota {$dados['numero']}/{$dados['serie']} do prestador {$dados['cnpj_prestador']} "
                            . "repetida no arquivo (já informada na linha {$notasDoArquivo[$chaveNota]})."
                        );
                    }

                    $servico = $this->classificador->buscar($this->con, $dados['codigo_lc116']);

                    if (!$servico) {
                        throw new InvalidArgumentException("serviço {$dados['codigo_lc116']} não cadastrado.");
                    }

                    $calculo = $this->calculadora->calcular(
                        $dados['valor_servico'],
                        (float)$servico['aliquota_iss']
                    );

                    $prestadorId = $this->obterPrestador($dados);
                    $tomadorId = $this->obterTomador($dados);
                    $this->inserirNota($importacaoId, $dados, $prestadorId, $tomadorId, $servico, $calculo);

                    mysqli_query($this->con, 'RELEASE SAVEPOINT linha');
                    $notasDoArquivo[$chaveNota] = $numeroLinha;
                    $processadas++;
                    $issTotal += $calculo['valor_iss'];
                } catch (Throwable $e) {
                    mysqli_query($this->con, 'ROLLBACK TO SAVEPOINT linha');
                    $erros[] = [
                        'linha' => $numeroLinha,
                        'mensagem' => $e->getMessage(),
                        'original' => $linhaOriginal,
                    ];
                    $this->registrarErro($importacaoId, $numeroLinha, $e->getMessage(), $linhaOriginal);
                }
            }

            $status = count($erros) > 0 ? 'PROCESSADA_COM_ERROS' : 'PROCESSADA';
            $this->finalizarImportacao($importacaoId, $totalLinhas, $processadas, count($erros), $issTotal, $status);
            mysqli_commit($this->con);

            return compact('importacaoId', 'totalLinhas', 'processadas', 'erros', 'issTotal', 'status');
        } catch (Throwable $e) {
            mysqli_rollback($this->con);
            $this->registrarFalha($nomeArquivo);
            throw $e;
        }
    }

    private function lerLinha(string $linha): array
    {
        // Formato:
        // numero;serie;data_emissao;cnpj_prestador;razao_social_prestador;
        // documento_tomador;razao_social_tomador;codigo_lc116;municipio_prestacao;valor_servico
        $campos = array_map('trim', str_getcsv($linha, ';'));
        if (count($campos) !== 10) {
            throw new InvalidArgumentException('formato inválido: esperado 10 campos separados por ponto e vírgula.');
        }

        [$numero, $serie, $data, $cnpjPrestador, $razaoPrestador, $docTomador,
            $razaoTomador, $codigoLc116, $municipio, $valor] = $campos;

        if ($numero === '' || $data === '' || $cnpjPrestador === '' || $codigoLc116 === '' || $valor === '') {
            throw new InvalidArgumentException('campo obrigatório vazio.');
        }

        $cnpjPrestador = preg_replace('/\D/', '', $cnpjPrestador);
        $docTomador = preg_replace('/\D/', '', $docTomador);

        if (strlen($cnpjPrestador) !== 14) {
            throw new InvalidArgumentException('CNPJ do prestador deve possuir 14 dígitos.');
        }
        if ($docTomador !== '' && !in_array(strlen($docTomador), [11, 14], true)) {
            throw new InvalidArgumentException('CPF/CNPJ do tomador deve possuir 11 ou 14 dígitos.');
        }
        if ($municipio !== '' && !preg_match('/^\d{7}$/', $municipio)) {
            throw new InvalidArgumentException('município deve ser um código IBGE de 7 dígitos.');
        }

        return [
            'numero' => $numero,
            'serie' => $serie,
            'data_emissao' => $this->lerData($data)->format('Y-m-d H:i:s'),
            'cnpj_prestador' => $cnpjPrestador,
            'razao_prestador' => $razaoPrestador,
            'doc_tomador' => $docTomador,
            'razao_tomador' => $razaoTomador,
            'codigo_lc116' => $codigoLc116,
            'municipio' => $municipio,
            'valor_servico' => $this->lerValor($valor),
        ];
    }

    private function lerData(string $data): DateTime
    {
        // "!" zera os campos não informados (sem ele, "Y-m-d" herda a hora atual).
        // A comparação de volta rejeita datas impossíveis, que o PHP normalizaria
        // silenciosamente (2026-02-31 viraria 2026-03-03).
        foreach (['Y-m-d H:i:s', 'Y-m-d'] as $formato) {
            $dataLida = DateTime::createFromFormat('!' . $formato, $data);
            if ($dataLida && $dataLida->format($formato) === $data) {
                return $dataLida;
            }
        }

        throw new InvalidArgumentException('data inválida: use uma data existente em YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS.');
    }

    private function lerValor(string $valor): float
    {
        if (preg_match('/^\d+(,\d{1,2})?$/', $valor)) {
            // 1000 | 1000,5 | 1000,50
            $normalizado = str_replace(',', '.', $valor);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $valor)) {
            // 1.000 | 1.000,50 | 1.234.567,89 (ponto como separador de milhar)
            $normalizado = str_replace(['.', ','], ['', '.'], $valor);
        } elseif (preg_match('/^\d+\.\d{1,2}$/', $valor)) {
            // 1000.5 | 1000.50 (ponto como separador decimal)
            $normalizado = $valor;
        } else {
            throw new InvalidArgumentException('valor do serviço inválido: use 1000,50, 1.000,50 ou 1000.50.');
        }

        return (float)$normalizado;
    }

    private function criarImportacao(string $arquivo): int
    {
        $stmt = mysqli_prepare($this->con, 'INSERT INTO importacoes (arquivo) VALUES (?)');
        mysqli_stmt_bind_param($stmt, 's', $arquivo);
        mysqli_stmt_execute($stmt);
        $id = mysqli_insert_id($this->con);
        mysqli_stmt_close($stmt);
        return $id;
    }

    private function obterPrestador(array $d): int
    {
        $sql = 'INSERT INTO prestadores (cnpj, razao_social, municipio_ibge, uf)
                VALUES (?, ?, ?, NULL)
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), razao_social = VALUES(razao_social), municipio_ibge = VALUES(municipio_ibge)';
        $stmt = mysqli_prepare($this->con, $sql);
        mysqli_stmt_bind_param($stmt, 'sss', $d['cnpj_prestador'], $d['razao_prestador'], $d['municipio']);
        mysqli_stmt_execute($stmt);
        $id = mysqli_insert_id($this->con);
        mysqli_stmt_close($stmt);
        return $id;
    }

    private function obterTomador(array $d): ?int
    {
        if ($d['doc_tomador'] === '') {
            return null;
        }

        $sql = 'INSERT INTO tomadores (cpf_cnpj, razao_social, municipio_ibge, uf)
                VALUES (?, ?, ?, NULL)
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), razao_social = VALUES(razao_social), municipio_ibge = VALUES(municipio_ibge)';
        $stmt = mysqli_prepare($this->con, $sql);
        mysqli_stmt_bind_param($stmt, 'sss', $d['doc_tomador'], $d['razao_tomador'], $d['municipio']);
        mysqli_stmt_execute($stmt);
        $id = mysqli_insert_id($this->con);
        mysqli_stmt_close($stmt);
        return $id;
    }

    private function inserirNota(int $importacaoId, array $d, int $prestadorId, ?int $tomadorId, array $servico, array $calculo): void
    {
        $sql = 'INSERT INTO notas
            (importacao_id, numero, serie, data_emissao, prestador_id, tomador_id, servico_id,
             municipio_prestacao, valor_servico, base_calculo, aliquota_iss, valor_iss)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              importacao_id = VALUES(importacao_id), data_emissao = VALUES(data_emissao),
              tomador_id = VALUES(tomador_id), servico_id = VALUES(servico_id),
              municipio_prestacao = VALUES(municipio_prestacao),
              valor_servico = VALUES(valor_servico), base_calculo = VALUES(base_calculo),
              aliquota_iss = VALUES(aliquota_iss), valor_iss = VALUES(valor_iss), status = "CALCULADA"';

        $stmt = mysqli_prepare($this->con, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            'isssiiisdddd',
            $importacaoId,
            $d['numero'],
            $d['serie'],
            $d['data_emissao'],
            $prestadorId,
            $tomadorId,
            $servico['id'],
            $d['municipio'],
            $d['valor_servico'],
            $calculo['base_calculo'],
            $calculo['aliquota_iss'],
            $calculo['valor_iss']
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    private function registrarErro(int $importacaoId, int $linha, string $mensagem, string $original): void
    {
        $stmt = mysqli_prepare($this->con, 'INSERT INTO erros_importacao (importacao_id, numero_linha, mensagem, linha_original) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iiss', $importacaoId, $linha, $mensagem, $original);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    private function registrarFalha(string $arquivo): void
    {
        // Roda após o rollback (fora da transação), para deixar rastro da tentativa.
        // Se o próprio banco estiver fora do ar, apenas loga: a exceção original é que importa.
        try {
            $stmt = mysqli_prepare($this->con, "INSERT INTO importacoes (arquivo, status) VALUES (?, 'ERRO')");
            mysqli_stmt_bind_param($stmt, 's', $arquivo);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } catch (Throwable $falha) {
            error_log('Não foi possível registrar a importação com ERRO: ' . $falha->getMessage());
        }
    }

    private function finalizarImportacao(int $id, int $total, int $processadas, int $erros, float $issTotal, string $status): void
    {
        $stmt = mysqli_prepare($this->con, 'UPDATE importacoes SET total_linhas=?, total_processadas=?, total_erros=?, iss_total=?, status=? WHERE id=?');
        mysqli_stmt_bind_param($stmt, 'iiidsi', $total, $processadas, $erros, $issTotal, $status, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
