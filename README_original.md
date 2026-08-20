# ArquivosParaBanco

Aplicação PHP simples para **importar clientes a partir de um arquivo `.txt`** e salvá-los em um banco de dados MySQL. Exercício do SENAI, refatorado com foco em **segurança, integridade dos dados e usabilidade**.

---

## Sobre o projeto

O sistema permite que o usuário faça upload de um arquivo `.txt` contendo uma lista de clientes (um por linha, campos separados por `;`), visualize o conteúdo antes de enviar, e importe os dados diretamente para uma tabela `clientes` no MySQL — com validação linha a linha e relatório do resultado ao final.

---

## Estrutura do projeto

```
ArquivosParaBanco/
├── README.md
└── Senai.Exercicio.ArquivosParaoBanco/
    ├── BancoDeDados.sql        # Script de criação do banco e da tabela
    └── src/
        ├── index.php           # Formulário de upload
        ├── processar.php       # Leitura do TXT e inserção no banco
        ├── conexao.php         # Conexão com o MySQL
        └── arquivo.txt         # Arquivo de exemplo para teste
```

---

## Requisitos

- PHP **7.4+** (com extensão `mysqli` habilitada)
- MySQL ou MariaDB
- Servidor local (Apache/Nginx) ou o servidor embutido do PHP (`php -S`)

---

## Como executar

### 1. Criar o banco de dados

Execute o script `BancoDeDados.sql` no seu MySQL:

```bash
mysql -u root -p < Senai.Exercicio.ArquivosParaoBanco/BancoDeDados.sql
```

Isso cria o banco `sistema` e a tabela `clientes`, já em `utf8mb4` (suporte a acentuação) e com restrições de unicidade em `cpf` e `email`.

### 2. Configurar a conexão

Edite `src/conexao.php` caso suas credenciais do MySQL sejam diferentes das padrão:

```php
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "sistema";
```

### 3. Rodar o servidor

Usando o servidor embutido do PHP, a partir da pasta `src/`:

```bash
php -S localhost:8000
```

Acesse **http://localhost:8000/index.php** no navegador.

---

## Formato do arquivo TXT

Cada linha do arquivo deve conter **4 campos separados por ponto e vírgula (`;`)**, na seguinte ordem:

```
nome;telefone;email;cpf
```

**Exemplo:**

```
João Silva;11900000000;joao.silva@email.com;10000000000
Maria Souza;11900000001;maria.souza@email.com;10000000001
```

| Campo     | Descrição                          | Validação aplicada                      |
|-----------|-------------------------------------|-------------------------------------------|
| `nome`    | Nome completo do cliente           | Não pode estar vazio                      |
| `telefone`| Telefone com DDD                   | Não pode estar vazio                      |
| `email`   | E-mail do cliente                  | Deve ser um e-mail válido                 |
| `cpf`     | CPF (com ou sem pontuação)         | Deve conter exatamente 11 dígitos numéricos |

Linhas em branco são ignoradas automaticamente. Linhas com formato inválido são reportadas ao final da importação, sem interromper o processo.

---

## Como usar (fluxo da aplicação)

1. Acesse `index.php` e selecione um arquivo `.txt` no campo de upload.
2. Clique em **"Carregar Conteúdo"** para pré-visualizar os dados na tela antes de enviar.
3. Clique em **"Salvar no Banco de Dados"** para iniciar a importação.
4. Ao final, `processar.php` exibe um relatório com:
   - Quantidade de clientes **inseridos com sucesso**;
   - Quantidade de linhas **ignoradas**, com o motivo de cada uma (formato inválido, campo vazio, e-mail inválido, CPF inválido ou duplicidade de CPF/e-mail).

---

## Estrutura da tabela `clientes`

```sql
CREATE TABLE clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    telefone VARCHAR(15) NOT NULL,
    email VARCHAR(100) NOT NULL,
    cpf VARCHAR(14) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_clientes_cpf (cpf),
    UNIQUE KEY uk_clientes_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Melhorias aplicadas nesta versão

| Área | Antes | Depois |
|------|-------|--------|
| **Segurança** | SQL montado por concatenação de strings (SQL Injection) | *Prepared statements* com `mysqli_prepare` / `bind_param` |
| **Integridade** | Sem restrição de duplicidade — reenviar o arquivo duplicava todos os clientes | `UNIQUE KEY` em `cpf` e `email`, com tratamento de erro amigável |
| **Codificação** | Sem charset definido — nomes acentuados podiam corromper | Conexão e tabela configuradas em `utf8mb4` |
| **Validação** | Linha mal formatada gerava erro e travava a importação | Cada linha é validada individualmente; linhas inválidas são reportadas, o processo continua |
| **Tratamento de erros** | Erros do MySQL eram silenciosos (`mysqli_error`) | `mysqli_report` ativo, exceções tratadas com `try/catch` |
| **Upload** | Só validado no navegador (`accept=".txt"`), facilmente contornável | Validação de erro de upload e extensão também no servidor |
| **Usabilidade** | Nenhum retorno detalhado ao usuário | Relatório final com total de inseridos e lista de linhas ignoradas com o motivo |
| **Performance** | Inserções linha a linha sem transação | Todas as inserções agrupadas em uma única transação (`begin_transaction` / `commit`) |

---

## Observações importantes

- Se você já possui uma tabela `clientes` criada com a versão antiga do script, será necessário recriá-la (ou adicionar as `UNIQUE KEY` via `ALTER TABLE`) para aproveitar a proteção contra duplicidade. Dados já duplicados impedirão a criação dessas chaves.
- Este projeto tem fins didáticos; para uso em produção, recomenda-se ainda adicionar autenticação de acesso, limite de tamanho de upload e criptografia de dados sensíveis (como CPF).

---

## Créditos

Exercício desenvolvido como parte do curso técnico do **SENAI**.
