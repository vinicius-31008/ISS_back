<?php

class Autenticacao
{
    private const VALIDADE_SESSAO_HORAS = 8;

    public const MENSAGEM_TROCAR_SENHA =
        'Troque a senha antes de continuar: POST /src/api/trocar-senha.php com {"senha_atual", "nova_senha"}.';

    // Hash de uma senha qualquer: usado quando o e-mail não existe, para que o tempo
    // de resposta não revele quais e-mails estão cadastrados.
    private const HASH_FICTICIO = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    private mysqli $con;

    public function __construct(mysqli $con)
    {
        $this->con = $con;
    }

    /**
     * Valida e-mail e senha e abre uma sessão. Null se as credenciais forem inválidas
     * ou se o usuário/empresa estiver inativo.
     */
    public function entrar(string $email, string $senha): ?array
    {
        $usuario = $this->consultarUm(
            'SELECT u.id, u.nome, u.email, u.perfil, u.trocar_senha, u.senha_hash, u.ativo,
                    e.id AS empresa_id, e.cnpj AS empresa_cnpj, e.razao_social AS empresa_razao_social, e.ativo AS empresa_ativo
             FROM usuarios u
             JOIN empresas e ON e.id = u.empresa_id
             WHERE u.email = ?',
            's',
            [$email]
        );

        $senhaOk = password_verify($senha, $usuario['senha_hash'] ?? self::HASH_FICTICIO);

        if (!$usuario || !$senhaOk || !(int)$usuario['ativo'] || !(int)$usuario['empresa_ativo']) {
            return null;
        }

        $usuarioId = (int)$usuario['id'];
        $token = bin2hex(random_bytes(32));

        $this->executar('DELETE FROM sessoes WHERE usuario_id = ? AND expira_em <= NOW()', 'i', [$usuarioId]);
        $this->executar(
            'INSERT INTO sessoes (usuario_id, token_hash, expira_em)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . self::VALIDADE_SESSAO_HORAS . ' HOUR))',
            'is',
            [$usuarioId, hash('sha256', $token)]
        );

        $sessao = $this->consultarUm('SELECT expira_em FROM sessoes WHERE id = ?', 'i', [mysqli_insert_id($this->con)]);

        return [
            'token' => $token,
            'expira_em' => $sessao['expira_em'],
        ] + $this->formatarUsuario($usuario);
    }

    /**
     * Usuário dono de um token válido (não expirado, usuário e empresa ativos), ou null.
     */
    public function usuarioDoToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $usuario = $this->consultarUm(
            'SELECT u.id, u.nome, u.email, u.perfil, u.trocar_senha,
                    e.id AS empresa_id, e.cnpj AS empresa_cnpj, e.razao_social AS empresa_razao_social
             FROM sessoes s
             JOIN usuarios u ON u.id = s.usuario_id
             JOIN empresas e ON e.id = u.empresa_id
             WHERE s.token_hash = ? AND s.expira_em > NOW() AND u.ativo = 1 AND e.ativo = 1',
            's',
            [hash('sha256', $token)]
        );

        return $usuario ? $this->formatarUsuario($usuario) : null;
    }

    public function sair(string $token): void
    {
        $this->executar('DELETE FROM sessoes WHERE token_hash = ?', 's', [hash('sha256', $token)]);
    }

    /**
     * Troca a senha do próprio usuário e libera a conta (trocar_senha = 0).
     * Encerra as demais sessões dele, mantendo a do token atual. False se a senha atual
     * não conferir. A nova senha já deve vir validada (CadastroUsuarios::validarSenha).
     */
    public function trocarSenha(int $usuarioId, string $senhaAtual, string $novaSenha, string $tokenAtual): bool
    {
        $usuario = $this->consultarUm('SELECT senha_hash FROM usuarios WHERE id = ?', 'i', [$usuarioId]);

        if (!$usuario || !password_verify($senhaAtual, $usuario['senha_hash'])) {
            return false;
        }

        $this->executar(
            'UPDATE usuarios SET senha_hash = ?, trocar_senha = 0 WHERE id = ?',
            'si',
            [password_hash($novaSenha, PASSWORD_DEFAULT), $usuarioId]
        );
        $this->executar(
            'DELETE FROM sessoes WHERE usuario_id = ? AND token_hash <> ?',
            'is',
            [$usuarioId, hash('sha256', $tokenAtual)]
        );

        return true;
    }

    /**
     * Token do cabeçalho "Authorization: Bearer <token>", ou '' se ausente.
     */
    public static function tokenDaRequisicao(): string
    {
        // Apache com PHP em CGI/FastCGI só repassa o cabeçalho via REDIRECT_* (ver src/.htaccess).
        $cabecalho = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if ($cabecalho === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $nome => $valor) {
                if (strcasecmp($nome, 'Authorization') === 0) {
                    $cabecalho = $valor;
                }
            }
        }

        return preg_match('/^Bearer\s+(\S+)$/i', trim($cabecalho), $m) ? $m[1] : '';
    }

    private function formatarUsuario(array $u): array
    {
        return [
            'usuario' => [
                'id' => (int)$u['id'],
                'nome' => $u['nome'],
                'email' => $u['email'],
                'perfil' => $u['perfil'],
                // true = senha provisória: o token só serve para trocar-senha.php.
                'trocar_senha' => (bool)$u['trocar_senha'],
            ],
            'empresa' => [
                'id' => (int)$u['empresa_id'],
                'cnpj' => $u['empresa_cnpj'],
                'razao_social' => $u['empresa_razao_social'],
            ],
        ];
    }

    private function consultarUm(string $sql, string $tipos, array $params): ?array
    {
        $stmt = mysqli_prepare($this->con, $sql);
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
        mysqli_stmt_execute($stmt);
        $linha = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        return $linha ?: null;
    }

    private function executar(string $sql, string $tipos, array $params): void
    {
        $stmt = mysqli_prepare($this->con, $sql);
        mysqli_stmt_bind_param($stmt, $tipos, ...$params);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
