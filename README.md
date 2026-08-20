# Importador Fiscal TXT -> MySQL -> ISS

Projeto adaptado do exercício original de importação de TXT para uma mecânica de backend fiscal.

## Fluxo

TXT -> validação -> classificação do serviço -> regra cadastrada -> cálculo do ISS -> MySQL.

## Formato do TXT

Cada linha deve conter 10 campos separados por `;`:

```text
numero;serie;data_emissao;cnpj_prestador;razao_social_prestador;documento_tomador;razao_social_tomador;codigo_lc116;municipio_ibge;valor_servico
```

Exemplo:

```text
1001;A;2026-08-20;12345678000190;Empresa Exemplo Ltda;98765432000110;Cliente Exemplo Ltda;01.01;3550407;10000,00
```

## Cálculo

A aplicação procura `codigo_lc116` na tabela `servicos`. A alíquota cadastrada para o serviço é usada para calcular:

`base de cálculo = valor do serviço`

`ISS = base de cálculo x alíquota / 100`

O resultado fica registrado na tabela `notas`.

## Instalação

1. Importe `BancoDeDados.sql` no MySQL/MariaDB.
2. Confira usuário, senha e banco em `src/conexao.php`.
3. Coloque a pasta `src` no servidor PHP (WAMP/XAMPP/Apache).
4. Abra `src/index.php`.
5. Cadastre na tabela `servicos` as regras oficiais aplicáveis ao seu município antes de usar em produção.

## Observação fiscal

O registro de exemplo de `01.01` é apenas demonstrativo. A aplicação foi construída para manter NBS, indOp e cClassTrib associados ao serviço, mas esses campos não devem ser preenchidos com valores presumidos. Para produção, carregue os dados oficiais da base normativa/documental utilizada pelo projeto.

## Arquitetura

- `ImportadorTxt.php`: orquestra a importação.
- `ClassificadorServico.php`: encontra a classificação cadastrada.
- `CalculadoraIss.php`: executa o cálculo matemático.
- `processar.php`: camada HTTP que recebe o upload.
- `BancoDeDados.sql`: estrutura de persistência e auditoria.
