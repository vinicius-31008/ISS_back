<?php

/**
 * Administrador de primeiro acesso.
 *
 * Só é criado enquanto o banco não tem NENHUM usuário (instalação nova): depois que
 * existir qualquer usuário, estas credenciais deixam de ser recriadas. Nasce com
 * trocar_senha = 1, então até a senha ser trocada o token dele só serve para
 * /src/api/trocar-senha.php — a senha abaixo, que é pública, não dá acesso a nada.
 */
class UsuarioPadrao
{
    public const EMAIL = 'admin@iss.local';
    public const SENHA = 'admin123';
    public const NOME = 'Administrador';

    // Mesmo CNPJ da empresa padrão da migração 002: num banco migrado, o admin
    // padrão entra nela e já enxerga os dados anteriores.
    public const EMPRESA_CNPJ = '00000000000000';
    public const EMPRESA_RAZAO_SOCIAL = 'Empresa padrão';

    public static function criarSeNecessario(mysqli $con): void
    {
        $existeUsuario = mysqli_fetch_row(mysqli_query($con, 'SELECT EXISTS(SELECT 1 FROM usuarios)'))[0];
        if ($existeUsuario) {
            return;
        }

        // ON DUPLICATE / IGNORE: dois logins simultâneos no banco vazio não geram erro
        // (o segundo encontra a empresa e o usuário já criados pelo primeiro).
        $stmt = mysqli_prepare(
            $con,
            'INSERT INTO empresas (cnpj, razao_social) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $cnpj = self::EMPRESA_CNPJ;
        $razaoSocial = self::EMPRESA_RAZAO_SOCIAL;
        mysqli_stmt_bind_param($stmt, 'ss', $cnpj, $razaoSocial);
        mysqli_stmt_execute($stmt);
        $empresaId = mysqli_insert_id($con);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare(
            $con,
            "INSERT IGNORE INTO usuarios (empresa_id, nome, email, senha_hash, perfil, trocar_senha)
             VALUES (?, ?, ?, ?, 'ADMIN', 1)"
        );
        $nome = self::NOME;
        $email = self::EMAIL;
        $senhaHash = password_hash(self::SENHA, PASSWORD_DEFAULT);
        mysqli_stmt_bind_param($stmt, 'isss', $empresaId, $nome, $email, $senhaHash);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        error_log('Usuário padrão de primeiro acesso criado: ' . self::EMAIL . ' (troca de senha obrigatória).');
    }
}
