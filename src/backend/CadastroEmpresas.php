<?php

class CadastroEmpresas extends Cadastro
{
    private const CAMPOS = ['cnpj', 'razao_social', 'ativo'];

    private const SELECT =
        'SELECT e.id, e.cnpj, e.razao_social, e.ativo, e.criado_em,
                (SELECT COUNT(*) FROM usuarios u WHERE u.empresa_id = e.id AND u.ativo = 1) AS usuarios_ativos
         FROM empresas e';

    /**
     * Filtros (opcionais, já validados pelo endpoint): busca (trecho da razão social
     * ou do CNPJ) e ativo (bool).
     */
    public function listar(array $filtros, int $pagina, int $porPagina): array
    {
        $igualdades = [];
        if (isset($filtros['ativo'])) {
            $igualdades['e.ativo'] = ['i', (int)$filtros['ativo']];
        }

        [$where, $tipos, $params] = $this->montarFiltros(
            $filtros['busca'] ?? null,
            ['e.razao_social', 'e.cnpj'],
            $igualdades
        );

        $total = (int)$this->consultar("SELECT COUNT(*) AS total FROM empresas e $where", $tipos, $params)[0]['total'];

        $empresas = $this->consultar(
            self::SELECT . " $where ORDER BY e.razao_social, e.id LIMIT ? OFFSET ?",
            $tipos . 'ii',
            array_merge($params, [$porPagina, ($pagina - 1) * $porPagina])
        );

        return [
            'empresas' => array_map([$this, 'formatar'], $empresas),
            'paginacao' => $this->paginacao($total, $pagina, $porPagina),
        ];
    }

    public function buscar(int $id): ?array
    {
        $empresa = $this->consultar(self::SELECT . ' WHERE e.id = ?', 'i', [$id]);
        return $empresa ? $this->formatar($empresa[0]) : null;
    }

    public function criar(array $dados): array
    {
        $this->exigirCamposConhecidos($dados, self::CAMPOS);

        $cnpj = $this->exigir($this->lerCnpj($dados, 'cnpj'), 'cnpj');
        $razaoSocial = $this->exigir($this->lerTexto($dados, 'razao_social', 200), 'razao_social');
        $ativo = $this->lerAtivo($dados) ?? true;

        $this->executar(
            'INSERT INTO empresas (cnpj, razao_social, ativo) VALUES (?, ?, ?)',
            'ssi',
            [$cnpj, $razaoSocial, (int)$ativo],
            "já existe uma empresa com o CNPJ $cnpj."
        );

        return $this->buscar(mysqli_insert_id($this->con));
    }

    /**
     * Atualiza só os campos informados. "ativo": true reativa uma empresa desativada.
     */
    public function atualizar(int $id, array $dados): array
    {
        $this->exigirExistente($id);
        $this->exigirCamposConhecidos($dados, self::CAMPOS);

        $campos = [];
        if (($cnpj = $this->lerCnpj($dados, 'cnpj')) !== null) {
            $campos['cnpj'] = ['s', $cnpj];
        }
        if (($razaoSocial = $this->lerTexto($dados, 'razao_social', 200)) !== null) {
            $campos['razao_social'] = ['s', $razaoSocial];
        }
        if (($ativo = $this->lerAtivo($dados)) !== null) {
            $campos['ativo'] = ['i', (int)$ativo];
        }

        // Só o CNPJ tem chave única: a duplicidade, se houver, é dele.
        $this->atualizarCampos('empresas', $id, $campos, "já existe uma empresa com o CNPJ $cnpj.");

        return $this->buscar($id);
    }

    /**
     * "Exclusão" lógica: a empresa tem importações e notas fiscais que não podem sumir.
     * Os usuários dela deixam de conseguir usar a API na hora (o token é recusado).
     */
    public function desativar(int $id): void
    {
        $this->exigirExistente($id);
        $this->executar('UPDATE empresas SET ativo = 0 WHERE id = ?', 'i', [$id]);
    }

    /** Ativo informado no corpo (para o endpoint impedir o admin de desativar a própria empresa). */
    public function ativoInformado(array $dados): ?bool
    {
        return $this->lerAtivo($dados);
    }

    private function exigirExistente(int $id): void
    {
        if ($this->buscar($id) === null) {
            throw new ErroCadastro("Empresa $id não encontrada.", 404);
        }
    }

    private function formatar(array $e): array
    {
        return [
            'id' => (int)$e['id'],
            'cnpj' => $e['cnpj'],
            'razao_social' => $e['razao_social'],
            'ativo' => (bool)$e['ativo'],
            'usuarios_ativos' => (int)$e['usuarios_ativos'],
            'criado_em' => $e['criado_em'],
        ];
    }
}
