<?php

/**
 * Exporta uma nota no TXT de importação do SIGISSWEB (layout 2.0), sistema de ISS
 * usado pela Prefeitura de Santa Bárbara d'Oeste/SP (santabarbara.sigissweb.com).
 *
 * Arquivo de largura fixa, codificação ANSI (Windows-1252), uma linha por registro:
 *   0 - dados da prefeitura   1 - dados da empresa (prestador)
 *   2 - dados da NF e destinatário   9 - quantidade de NF no arquivo
 * As posições abaixo seguem o layout (1-based, como no documento da prefeitura).
 */
class ExportadorIssSantaBarbara
{
    const NOME_PREFEITURA = "PREFEITURA MUNICIPAL DE SANTA BARBARA D'OESTE";
    const CNPJ_PREFEITURA = '46422408000152';
    const NOME_SISTEMA = 'ISS_BACK';

    // S = Simples Nacional, V = Variável, M = MEI, L = Especial
    const REGIMES = ['S', 'V', 'M', 'L'];
    // N = não retido, S = retido no município, F = retido fora,
    // D = devido fora (não retido), I = sem incidência
    const RETENCOES = ['N', 'S', 'F', 'D', 'I'];

    private mysqli $con;

    public function __construct(mysqli $con)
    {
        $this->con = $con;
    }

    /**
     * Gera o arquivo da nota $notaId, se ela pertencer a $empresaId (senão, null).
     * $homologacao troca o tipo do registro 0 para "X", que o SIGISSWEB só valida, sem importar.
     * Lança ErroCadastro (422) quando a nota não cabe no layout.
     *
     * @return array{arquivo: string, conteudo: string}|null
     */
    public function exportarNota(int $empresaId, int $notaId, string $regime, string $retencao, bool $homologacao): ?array
    {
        $nota = $this->buscarNota($empresaId, $notaId);
        if ($nota === null) {
            return null;
        }

        $this->validar($nota, $regime, $retencao);

        $emissao = new DateTime($nota['data_emissao']);
        $linhas = [
            $this->registroPrefeitura($emissao, $homologacao),
            $this->registroEmpresa($nota['cnpj_prestador']),
            $this->registroNota($nota, $emissao, $regime, $retencao),
            '9' . str_pad('1', 6, '0', STR_PAD_LEFT),
        ];

        $conteudo = implode("\r\n", $linhas) . "\r\n";

        return [
            // Nome usado pelos emissores do SIGISSWEB: CNPJ + competência (MMAAAA) + .TXT
            'arquivo' => $nota['cnpj_prestador'] . $emissao->format('mY') . '.TXT',
            'conteudo' => mb_convert_encoding($conteudo, 'Windows-1252', 'UTF-8'),
        ];
    }

    private function buscarNota(int $empresaId, int $notaId): ?array
    {
        $stmt = mysqli_prepare(
            $this->con,
            'SELECT n.numero, n.serie, n.data_emissao, n.valor_servico, n.base_calculo,
                    n.aliquota_iss, n.valor_iss, n.status,
                    p.cnpj AS cnpj_prestador,
                    t.cpf_cnpj AS documento_tomador, t.razao_social AS razao_tomador, t.uf AS uf_tomador,
                    s.codigo_lc116, s.descricao AS descricao_servico
             FROM notas n
             JOIN prestadores p ON p.id = n.prestador_id
             LEFT JOIN tomadores t ON t.id = n.tomador_id
             JOIN servicos s ON s.id = n.servico_id
             WHERE n.id = ? AND p.empresa_id = ?'
        );
        mysqli_stmt_bind_param($stmt, 'ii', $notaId, $empresaId);
        mysqli_stmt_execute($stmt);
        $nota = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return $nota ?: null;
    }

    /** Regras do layout que o SIGISSWEB rejeitaria na importação. */
    private function validar(array $nota, string $regime, string $retencao): void
    {
        if (!preg_match('/^\d{1,10}$/', $nota['numero'])) {
            throw new ErroCadastro("número da nota ({$nota['numero']}) deve ter só dígitos, até 10, para o SIGISSWEB.", 422);
        }
        if ($nota['serie'] === '' || mb_strlen($nota['serie']) > 3) {
            throw new ErroCadastro('a série da nota deve ter de 1 a 3 caracteres para o SIGISSWEB.', 422);
        }
        if ($nota['documento_tomador'] === null) {
            throw new ErroCadastro('a nota não tem tomador; o SIGISSWEB exige o CPF/CNPJ do destinatário.', 422);
        }
        if ($retencao === 'S' && strlen($nota['documento_tomador']) === 11) {
            throw new ErroCadastro('o ISS não pode ser retido quando o tomador é pessoa física.', 422);
        }
        if (new DateTime($nota['data_emissao']) > new DateTime()) {
            throw new ErroCadastro('o SIGISSWEB não aceita nota com data de emissão futura.', 422);
        }
        if (strlen($this->codigoServico($nota['codigo_lc116'])) > 6) {
            throw new ErroCadastro("código de serviço {$nota['codigo_lc116']} não cabe nas 6 posições do layout.", 422);
        }
        if (!in_array($regime, self::REGIMES, true) || !in_array($retencao, self::RETENCOES, true)) {
            throw new InvalidArgumentException('regime ou retenção fora dos valores do layout.');
        }
    }

    private function registroPrefeitura(DateTime $emissao, bool $homologacao): string
    {
        return ($homologacao ? 'X' : '0')                      // 1
            . $this->texto('SIGISSWEB', 10)                    // 2
            . $this->texto(self::NOME_PREFEITURA, 50)          // 12
            . self::CNPJ_PREFEITURA                            // 62
            . date('dmY')                                      // 76  data de geração
            . $emissao->format('mY')                           // 84  competência das NF
            . $this->texto(self::NOME_SISTEMA, 15)             // 90
            . '20'                                             // 105 versão do layout
            . str_repeat(' ', 43)                              // 107-149 sem uso
            . '0';                                             // 150 identificador
    }

    private function registroEmpresa(string $cnpj): string
    {
        return '1'                                             // 1
            . $this->texto($cnpj, 14)                          // 2
            . str_repeat(' ', 84)                              // 16-99 sem uso
            . '1';                                             // 100 identificador
    }

    private function registroNota(array $nota, DateTime $emissao, string $regime, string $retencao): string
    {
        $documento = $nota['documento_tomador'];
        $deducao = max(0, (float)$nota['valor_servico'] - (float)$nota['base_calculo']);
        $zero = $this->valor(0);
        $aliquotaZero = $this->aliquota(0);
        // PIS, COFINS, CSLL, IRRF e INSS: o sistema não calcula retenções federais.
        $federais = str_repeat($zero . $aliquotaZero . $zero, 5);

        return '2'                                             // 1
            . $this->texto($nota['serie'], 3)                  // 2
            . $this->texto($nota['numero'], 10)                // 5   sem zeros à esquerda
            . $this->texto($this->codigoServico($nota['codigo_lc116']), 6) // 15
            . $emissao->format('dmY')                          // 21
            . 'N'                                              // 29  destinatário estrangeiro
            . (strlen($documento) === 11 ? 'F ' : 'J ')        // 30
            . $this->texto($documento, 14)                     // 32
            . $this->texto((string)$nota['razao_tomador'], 100) // 46
            . $this->texto('', 15)                             // 146 inscr. municipal (não cadastrada)
            . $this->texto('', 15)                             // 161 inscr. estadual (não cadastrada)
            . $this->texto('', 60)                             // 176 endereço (não cadastrado)
            . $this->texto('', 10)                             // 236 número
            . $this->texto('', 40)                             // 246 complemento
            . $this->texto('', 8)                              // 286 CEP
            . $this->texto('', 100)                            // 294 bairro
            . $this->texto('', 100)                            // 394 cidade
            . $this->texto((string)$nota['uf_tomador'], 2)     // 494
            . $this->texto('', 50)                             // 496 país
            . $this->texto('', 30)                             // 546 telefone
            . $this->texto('', 120)                            // 576 e-mail
            . $this->valor((float)$nota['valor_servico'])      // 696 valor total
            . $this->valor($deducao)                           // 710 dedução
            . $this->valor((float)$nota['base_calculo'])       // 724 valor do serviço (base)
            . $this->aliquota((float)$nota['aliquota_iss'])    // 738
            . $this->valor((float)$nota['valor_iss'])          // 743
            . $federais                                        // 757-921
            . $regime                                          // 922
            . $retencao                                        // 923
            . ($nota['status'] === 'CANCELADA' ? 'S' : 'N')    // 924
            . $this->texto('', 40)                             // 925 forma de pagamento
            . '2'                                              // 965 identificador
            // 966 descrição (até 2000); o "|" é a quebra de linha do SIGISSWEB.
            . mb_substr(str_replace(["\r\n", "\r", "\n"], '|', $nota['descricao_servico']), 0, 2000);
    }

    /** "01.01" → "1.01": o layout usa o item da LC 116 sem zero à esquerda (ex.: "7.01"). */
    private function codigoServico(string $codigo): string
    {
        return preg_replace('/^0(?=\d\.)/', '', trim($codigo));
    }

    /** Texto alinhado à esquerda, cortado/completado com espaços até $tamanho caracteres. */
    private function texto(string $valor, int $tamanho): string
    {
        $valor = mb_substr(trim($valor), 0, $tamanho);
        return $valor . str_repeat(' ', $tamanho - mb_strlen($valor));
    }

    /** Formato "Valor 11,2": 00000000000,00 (14 posições). */
    private function valor(float $valor): string
    {
        return str_pad(number_format($valor, 2, ',', ''), 14, '0', STR_PAD_LEFT);
    }

    /** Formato "Valor 2,2": 00,00 (5 posições), em percentual. */
    private function aliquota(float $aliquota): string
    {
        return str_pad(number_format($aliquota, 2, ',', ''), 5, '0', STR_PAD_LEFT);
    }
}
