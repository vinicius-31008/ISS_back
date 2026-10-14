<?php

class CadastroUsuarios extends Cadastro
{
    public const PERFIS = ['ADMIN', 'USUARIO'];

    private const CAMPOS = ['empresa_id', 'nome', 'email', 'senha', 'perfil', 'ativo'];

    // Nunca seleciona senha_hash: nada deste cadastro devolve a senha, nem como hash.
    private const SELECT =
        'SELECT u.id, u.nome, u.email, u.perfil, u.ativo, u.criado_em,
                e.id AS empresa_id, e.cnpj AS empresa_cnpj, e.razao_social AS empresa_razao_social
         FROM usuarios u
         JOIN empresas e ON e.id = u.empresa_id';

    /**
     * Filtros (opcionais, já validados pelo endpoint): busca (trecho do nome ou e-mail),
     * empresa_id, perfil e ativo (bool).
     */
    public function listar(array $filtros, int $pagina, int $porPagina): array
    {
        $igualdades = [];
        if (isset($filtros['empresa_id'])) {
            $igualdades['u.empresa_id'] = ['i', $filtros['empresa_id']];
        }
        if (isset($filtros['perfil'])) {
            $igualdades['u.perfil'] = ['s', $filtros['perfil']];
        }
        if (isset($filtros['ativo'])) {
            $igualdades['u.ativo'] = ['i', (int)$filtros['ativo']];
        }

        [$where, $tipos, $params] = $this->montarFiltros(
            $filtros['busca'] ?? null,
            ['u.nome', 'u.email'],
            $igualdades
        );

        $total = (int)$this->consultar("SELECT COUNT(*) AS total FROM usuarios u $where", $tipos, $params)[0]['total'];

        $usuarios = $this->consultar(
            self::SELECT . " $where ORDER BY u.nome, u.id LIMIT ? OFFSET ?",
            $tipos . 'ii',
            array_merge($params, [$porPagina, ($pagina - 1) * $porPagina])
        );

        return [
            'usuarios' => array_map([$this, 'formatar'], $usuarios),
            'paginacao' => $this->paginacao($total, $pagina, $porPagina),
        ];
    }

    public function buscar(int $id): ?array
    {
        $usuario = $this->consultar(self::SELECT . ' WHERE u.id = ?', 'i', [$id]);
        return $usuario ? $this->formatar($usuario[0]) : null;
    }

    public function criar(array $dados): array
    {
        $this->exigirCamposConhecidos($dados, self::CAMPOS);

        $empresaId = $this->exigir($this->lerId($dados, 'empresa_id'), 'empresa_id');
        $nome = $this->exigir($this->lerTexto($dados, 'nome', 150), 'nome');
        $email = $this->exigir($this->lerEmail($dados), 'email');
        $senha = $this->exigir($this->lerSenha($dados), 'senha');
        $perfil = $this->lerPerfil($dados) ?? 'USUARIO';
        $ativo = $this->lerAtivo($dados) ?? true;

        $this->exigirEmpresaAtiva($empresaId);

        $this->executar(
            'INSERT INTO usuarios (empresa_id, nome, email, senha_hash, perfil, ativo) VALUES (?, ?, ?, ?, ?, ?)',
            'issssi',
            [$empresaId, $nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil, (int)$ativo],
            "já existe um usuário com o e-mail $email."
        );

        return $this->buscar(mysqli_insert_id($this->con));
    }

    /**
     * Atualiza só os campos informados. Trocar a senha ou desativar encerra as sessões
     * abertas do usuário. "ativo": true reativa um usuário desativado.
     */
    public function atualizar(int $id, array $dados): array
    {
        $this->exigirExistente($id);
        $this->exigirCamposConhecidos($dados, self::CAMPOS);

        $campos = [];
        if (($empresaId = $this->lerId($dados, 'empresa_id')) !== null) {
            $this->exigirEmpresaAtiva($empresaId);
            $campos['empresa_id'] = ['i', $empresaId];
        }
        if (($nome = $this->lerTexto($dados, 'nome', 150)) !== null) {
            $campos['nome'] = ['s', $nome];
        }
        if (($email = $this->lerEmail($dados)) !== null) {
            $campos['email'] = ['s', $email];
        }
        if (($senha = $this->lerSenha($dados)) !== null) {
            $campos['senha_hash'] = ['s', password_hash($senha, PASSWORD_DEFAULT)];
        }
        if (($perfil = $this->lerPerfil($dados)) !== null) {
            $campos['perfil'] = ['s', $perfil];
        }
        if (($ativo = $this->lerAtivo($dados)) !== null) {
            $campos['ativo'] = ['i', (int)$ativo];
        }

        // Só o e-mail tem chave única: a duplicidade, se houver, é dele.
        $this->atualizarCampos('usuarios', $id, $campos, "já existe um usuário com o e-mail $email.");

        if ($senha !== null || $ativo === false) {
            $this->encerrarSessoes($id);
        }

        return $this->buscar($id);
    }

    /**
     * "Exclusão" lógica: as importações guardam quem as fez. Encerra as sessões abertas.
     */
    public function desativar(int $id): void
    {
        $this->exigirExistente($id);
        $this->executar('UPDATE usuarios SET ativo = 0 WHERE id = ?', 'i', [$id]);
        $this->encerrarSessoes($id);
    }

    /** Perfil e ativo informados no corpo (para o endpoint impedir o admin de se rebaixar/desativar). */
    public function perfilInformado(array $dados): ?string
    {
        return $this->lerPerfil($dados);
    }

    public function ativoInformado(array $dados): ?bool
    {
        return $this->lerAtivo($dados);
    }

    private function lerEmail(array $dados): ?string
    {
        $email = $this->lerTexto($dados, 'email', 255);

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ErroCadastro('email inválido.');
        }

        return $email;
    }

    private function lerSenha(array $dados): ?string
    {
        if (!isset($dados['senha'])) {
            return null;
        }
        if (!is_string($dados['senha'])) {
            throw new ErroCadastro('senha deve ser texto.');
        }

        self::validarSenha($dados['senha'], 'senha');

        return $dados['senha'];
    }

    /** Regra de senha do sistema (cadastro e troca de senha). */
    public static function validarSenha(string $senha, string $campo): void
    {
        // Sem trim: espaços fazem parte da senha. O bcrypt ignora o que passa de 72 bytes,
        // então senhas maiores dariam a falsa impressão de serem mais fortes.
        $tamanho = strlen($senha);
        if ($tamanho < 8 || $tamanho > 72) {
            throw new ErroCadastro("$campo deve ter entre 8 e 72 caracteres.");
        }
    }

    private function lerPerfil(array $dados): ?string
    {
        $perfil = $this->lerTexto($dados, 'perfil', 20);
        if ($perfil === null) {
            return null;
        }

        $perfil = strtoupper($perfil);
        if (!in_array($perfil, self::PERFIS, true)) {
            throw new ErroCadastro('perfil deve ser um destes: ' . implode(', ', self::PERFIS) . '.');
        }

        return $perfil;
    }

    private function exigirEmpresaAtiva(int $empresaId): void
    {
        $empresa = $this->consultar('SELECT ativo FROM empresas WHERE id = ?', 'i', [$empresaId]);

        if (!$empresa) {
            throw new ErroCadastro("empresa $empresaId não encontrada.");
        }
        if (!(int)$empresa[0]['ativo']) {
            throw new ErroCadastro("empresa $empresaId está desativada.");
        }
    }

    private function exigirExistente(int $id): void
    {
        if ($this->buscar($id) === null) {
            throw new ErroCadastro("Usuário $id não encontrado.", 404);
        }
    }

    private function encerrarSessoes(int $usuarioId): void
    {
        $this->executar('DELETE FROM sessoes WHERE usuario_id = ?', 'i', [$usuarioId]);
    }

    private function formatar(array $u): array
    {
        return [
            'id' => (int)$u['id'],
            'nome' => $u['nome'],
            'email' => $u['email'],
            'perfil' => $u['perfil'],
            'ativo' => (bool)$u['ativo'],
            'empresa' => [
                'id' => (int)$u['empresa_id'],
                'cnpj' => $u['empresa_cnpj'],
                'razao_social' => $u['empresa_razao_social'],
            ],
            'criado_em' => $u['criado_em'],
        ];
    }
}
