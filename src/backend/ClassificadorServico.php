<?php

class ClassificadorServico
{
    public function buscar(mysqli $con, string $codigoLc116): ?array
    {
        $sql = 'SELECT id, codigo_lc116, descricao, nbs, ind_op, cclass_trib, aliquota_iss
                FROM servicos
                WHERE codigo_lc116 = ? AND ativo = 1
                LIMIT 1';
        $stmt = mysqli_prepare($con, $sql);
        mysqli_stmt_bind_param($stmt, 's', $codigoLc116);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $servico = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($stmt);
        return $servico;
    }
}
