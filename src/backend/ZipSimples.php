<?php

/**
 * Gera um arquivo ZIP em memória sem depender da extensão zip do PHP.
 *
 * Os arquivos são guardados sem compressão (método 0, "stored"), o que basta para os
 * TXT de exportação e é aberto por qualquer descompactador (Windows, 7-Zip, unzip).
 */
class ZipSimples
{
    /**
     * @param array<string, string> $arquivos nome no ZIP => conteúdo
     */
    public static function gerar(array $arquivos): string
    {
        [$hora, $data] = self::dataHoraDos(time());
        $dados = '';
        $diretorio = '';

        foreach ($arquivos as $nome => $conteudo) {
            $nome = (string)$nome;
            $crc = crc32($conteudo);
            $tamanho = strlen($conteudo);
            $deslocamento = strlen($dados);

            // Cabeçalho local + dados do arquivo
            $dados .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $hora, $data, $crc, $tamanho, $tamanho, strlen($nome), 0)
                . $nome
                . $conteudo;

            // Entrada no diretório central
            $diretorio .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $hora, $data, $crc, $tamanho, $tamanho, strlen($nome), 0, 0, 0, 0, 0, $deslocamento)
                . $nome;
        }

        // Fim do diretório central
        $fim = pack('VvvvvVVv', 0x06054b50, 0, 0, count($arquivos), count($arquivos), strlen($diretorio), strlen($dados), 0);

        return $dados . $diretorio . $fim;
    }

    /** Data e hora no formato MS-DOS usado pelo ZIP. */
    private static function dataHoraDos(int $timestamp): array
    {
        $d = getdate($timestamp);
        $hora = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $data = (max(0, $d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];

        return [$hora, $data];
    }
}
