<?php
declare(strict_types=1);

/*
 * Cria um usuário (e a empresa dele, se o CNPJ ainda não estiver cadastrado).
 * Uso (linha de comando):
 *
 *   php scripts/criar_usuario.php --cnpj=12345678000190 --empresa="Empresa Exemplo Ltda" \
 *       --nome="Fulano de Tal" --email=fulano@exemplo.com --senha=SenhaForte123 --perfil=ADMIN
 *
 * --empresa só é obrigatório quando a empresa ainda não existe.
 * --perfil: ADMIN (gerencia empresas e usuários pela API) ou USUARIO (padrão).
 * Use este script para criar o primeiro ADMIN; os demais podem ser criados por ele
 * via /src/api/empresas.php e /src/api/usuarios.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opcoes = getopt('', ['cnpj:', 'empresa:', 'nome:', 'email:', 'senha:', 'perfil:']);

$cnpj = preg_replace('/\D/', '', (string)($opcoes['cnpj'] ?? ''));
$razaoSocial = trim((string)($opcoes['empresa'] ?? ''));
$nome = trim((string)($opcoes['nome'] ?? ''));
$email = trim((string)($opcoes['email'] ?? ''));
$senha = (string)($opcoes['senha'] ?? '');
$perfil = strtoupper(trim((string)($opcoes['perfil'] ?? 'USUARIO')));

if (strlen($cnpj) !== 14) {
    falhar('--cnpj deve possuir 14 dígitos.');
}
if ($nome === '') {
    falhar('--nome é obrigatório.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    falhar('--email inválido.');
}
// Mesma regra de CadastroUsuarios: o bcrypt ignora o que passa de 72 bytes.
if (strlen($senha) < 8 || strlen($senha) > 72) {
    falhar('--senha deve ter entre 8 e 72 caracteres.');
}
if (!in_array($perfil, ['ADMIN', 'USUARIO'], true)) {
    falhar('--perfil deve ser ADMIN ou USUARIO.');
}

require __DIR__ . '/../src/conexao.php';

mysqli_begin_transaction($con);

$stmt = mysqli_prepare($con, 'SELECT id, razao_social FROM empresas WHERE cnpj = ?');
mysqli_stmt_bind_param($stmt, 's', $cnpj);
mysqli_stmt_execute($stmt);
$empresa = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if ($empresa) {
    $empresaId = (int)$empresa['id'];
    echo "Empresa existente: {$empresa['razao_social']} (id $empresaId)\n";
} else {
    if ($razaoSocial === '') {
        falhar("empresa $cnpj não cadastrada: informe --empresa com a razão social para criá-la.");
    }

    $stmt = mysqli_prepare($con, 'INSERT INTO empresas (cnpj, razao_social) VALUES (?, ?)');
    mysqli_stmt_bind_param($stmt, 'ss', $cnpj, $razaoSocial);
    mysqli_stmt_execute($stmt);
    $empresaId = mysqli_insert_id($con);
    mysqli_stmt_close($stmt);
    echo "Empresa criada: $razaoSocial (id $empresaId)\n";
}

$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

try {
    $stmt = mysqli_prepare($con, 'INSERT INTO usuarios (empresa_id, nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'issss', $empresaId, $nome, $email, $senhaHash, $perfil);
    mysqli_stmt_execute($stmt);
    $usuarioId = mysqli_insert_id($con);
    mysqli_stmt_close($stmt);
} catch (mysqli_sql_exception $e) {
    mysqli_rollback($con);
    // 1062 = chave única violada (uk_usuario_email).
    falhar($e->getCode() === 1062 ? "já existe um usuário com o e-mail $email." : $e->getMessage());
}

mysqli_commit($con);
echo "Usuário criado: $nome <$email> (id $usuarioId, perfil $perfil)\n";

function falhar(string $mensagem): void
{
    fwrite(STDERR, "Erro: $mensagem\n");
    exit(1);
}
