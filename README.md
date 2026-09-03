# Importador Fiscal ISS — TXT → MySQL → ISS

API PHP que recebe um arquivo `.txt` com notas fiscais, classifica o serviço prestado (LC 116), calcula o ISS devido e grava tudo no MySQL. Inclui documentação interativa via **Swagger / OpenAPI**.

> Este projeto é uma evolução do exercício didático original de importação de clientes (SENAI). O histórico dessa versão anterior está preservado em [`README_original.md`](README_original.md).

Este README **resume e consolida** todos os demais READMEs do repositório (`README_API.md`, `README_original.md`, `README_SWAGGER_CORRIGIDO.md` e `frontend/README.md`), servindo como ponto único de entrada para entender e rodar o projeto.

---

## Sumário

- [Dependências](#dependências)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Como cada parte funciona](#como-cada-parte-funciona)
- [Fluxo de uma importação](#fluxo-de-uma-importação)
- [Formato do arquivo TXT](#formato-do-arquivo-txt)
- [Banco de dados](#banco-de-dados)
- [Como estartar o projeto](#como-estartar-o-projeto)
- [Documentação da API (Swagger / OpenAPI)](#documentação-da-api-swagger--openapi)
- [Endpoints](#endpoints)
- [Observação fiscal](#observação-fiscal)
- [Resumo dos demais READMEs do repositório](#resumo-dos-demais-readmes-do-repositório)

---

## Dependências

| Dependência | Versão / detalhe | Obrigatória? |
|---|---|---|
| **PHP** | `>= 7.4`, com extensão `mysqli` habilitada | Sim |
| **MySQL / MariaDB** | Qualquer versão compatível com o dump em `BancoDeDados.sql` (banco `sistema`, `utf8mb4`) | Sim |
| **Composer** | Gerenciador de dependências PHP | Sim (para gerar a doc Swagger) |
| **`zircote/swagger-php`** `^4.11` | Gera o contrato OpenAPI a partir de anotações no código (declarada como `require-dev` no `composer.json`) | Só para a documentação Swagger |
| **Servidor web** | Apache/XAMPP/WAMP **ou** o servidor embutido do PHP (`php -S`) | Sim (um dos dois) |
| **Navegador** | Para usar o frontend de exemplo (`src/index.php`) e o Swagger UI (CDN, sem instalação) | Opcional |

Não há dependências de frontend (Node/npm) — o cliente de exemplo é HTML + JavaScript puro, sem build step.

Credenciais padrão de banco usadas em `src/conexao.php` (ajustar se necessário):
```php
$servername = 'localhost';
$username   = 'root';
$password   = '';
$dbname     = 'sistema';
```

---

## Estrutura do projeto

```text
ISS_back-main/
├── composer.json                  # Dependências PHP (swagger-php para gerar a doc)
├── BancoDeDados.sql                # Script de criação do schema MySQL
├── notas_teste_iss.txt             # Arquivo de exemplo para testar a importação
├── README.md                       # Este arquivo
├── README_API.md                   # Documentação de referência da API (complementar)
├── README_original.md              # Histórico do exercício didático original
├── README_SWAGGER_CORRIGIDO.md     # Notas da correção do Swagger para PHP 7.4
│
├── frontend/
│   └── README.md                   # Observações sobre o cliente de exemplo
│
└── src/
    ├── index.php                   # Frontend de exemplo (HTML + JS puro), consome a API
    ├── processar.php               # Endpoint antigo, desativado (HTTP 410) — mantido por compatibilidade
    ├── conexao.php                 # Abre a conexão mysqli usada pela API
    ├── .htaccess                   # CORS e proteção de listagem de diretório (Apache)
    │
    ├── api/                        # Camada HTTP (o que fica exposto na web)
    │   ├── health.php               # GET  — healthcheck da API
    │   ├── importar.php             # POST — recebe o TXT, processa e retorna JSON
    │   ├── openapi.php              # Anotações OpenAPI globais (Info/Servers/Schemas)
    │   ├── gerar-swagger.php        # Gera o contrato openapi.json a partir das anotações
    │   └── docs.html                # Swagger UI, consome gerar-swagger.php
    │
    └── backend/                    # Regras de negócio (não é acessado diretamente pela web)
        ├── ImportadorTxt.php        # Orquestra a importação linha a linha
        ├── ClassificadorServico.php # Busca a regra de serviço (LC116) no banco
        └── CalculadoraIss.php       # Calcula base de cálculo e valor do ISS
```

**Regra de organização:** tudo que está em `src/api/` é um endpoint público (arquivo acessível diretamente por URL). Tudo que está em `src/backend/` é lógica interna, chamada pelos endpoints, e nunca deve ser acessada diretamente pelo navegador.

---

## Como cada parte funciona

### `src/api/health.php`
Endpoint `GET` sem dependência de banco. Serve só para o frontend (ou qualquer client) confirmar que a API está no ar antes de tentar enviar um arquivo grande.

### `src/api/importar.php`
Endpoint `POST` principal. Responsabilidades:
1. Configura headers de resposta (`Content-Type: application/json`) e CORS.
2. Converte erros e exceptions do PHP em respostas JSON padronizadas (evita devolver uma página HTML de erro para o front).
3. Valida a requisição: método HTTP, presença do arquivo, extensão `.txt`, se é realmente um upload válido, se não está vazio.
4. Instancia `ImportadorTxt` (injetando a conexão, o `ClassificadorServico` e a `CalculadoraIss`) e chama `processar()`.
5. Formata o resultado em JSON e responde.

### `src/backend/ImportadorTxt.php`
O coração da regra de negócio. Para cada linha do TXT:
- faz o parse dos 10 campos (`str_getcsv` com `;`);
- valida formato, tamanho de CNPJ/CPF, valor numérico, data e código IBGE;
- pergunta ao `ClassificadorServico` se o `codigo_lc116` está cadastrado e ativo;
- pede à `CalculadoraIss` o cálculo do imposto;
- grava (ou atualiza, via `ON DUPLICATE KEY`) o prestador, o tomador e a nota;
- linhas com erro **não interrompem o processo** — são coletadas e registradas em `erros_importacao`, e a importação termina com status `PROCESSADA_COM_ERROS`.

Toda a operação roda dentro de uma transação (`mysqli_begin_transaction` / `commit` / `rollback`): se algo inesperado quebrar no meio (não um erro de linha, mas uma falha real), nada fica gravado pela metade.

### `src/backend/ClassificadorServico.php`
Classe pequena e única responsabilidade: dado um `codigo_lc116`, busca na tabela `servicos` a descrição, NBS, indicador de operação, cClassTrib e a alíquota cadastrada.

### `src/backend/CalculadoraIss.php`
Só matemática: `base_calculo = valor_servico` e `valor_iss = base_calculo * aliquota / 100`, com validação de valores negativos/alíquota fora da faixa 0–100.

### `src/conexao.php`
Abre a conexão `mysqli` (`mysqli_report` em modo estrito, para que falhas de SQL virem exceções em vez de warnings silenciosos).

### `src/index.php`
Cliente de exemplo em HTML + JavaScript puro (sem framework). Testa o `health.php`, envia o arquivo via `FormData` para `importar.php` e renderiza o resultado. Serve como referência de como qualquer frontend (Angular/React/Vue/etc.) deve consumir a API.

### `src/processar.php`
Endpoint antigo (da versão anterior do projeto). Mantido apenas para responder `410 Gone` com uma mensagem apontando para o endpoint atual, evitando quebrar integrações antigas silenciosamente.

---

## Fluxo de uma importação

```text
Frontend (index.php ou outro client)
   |
   |  POST multipart/form-data (campo "arquivo")
   v
src/api/importar.php
   |
   +-- valida requisição e arquivo
   |
   v
ImportadorTxt::processar()
   |
   +-- para cada linha do TXT:
   |     +-- valida os 10 campos
   |     +-- ClassificadorServico::buscar(codigo_lc116)
   |     +-- CalculadoraIss::calcular(valor, aliquota)
   |     +-- grava prestador / tomador / nota
   |     +-- (em erro) registra em erros_importacao, segue para a próxima linha
   |
   +-- finaliza a importação (totais, status)
   v
Resposta JSON (sucesso, dados, erros[])
```

---

## Formato do arquivo TXT

Cada linha deve conter **10 campos separados por `;`**:

```text
numero;serie;data_emissao;cnpj_prestador;razao_social_prestador;documento_tomador;razao_social_tomador;codigo_lc116;municipio_ibge;valor_servico
```

Exemplo:

```text
1001;A;2026-08-20;12345678000190;Empresa Exemplo Ltda;98765432000110;Cliente Exemplo Ltda;01.01;3550407;10000,00
```

| Campo | Regra |
|---|---|
| `numero` / `data_emissao` / `cnpj_prestador` / `codigo_lc116` / `valor_servico` | obrigatórios |
| `cnpj_prestador` | 14 dígitos após remover pontuação |
| `documento_tomador` | opcional; se preenchido, 11 (CPF) ou 14 (CNPJ) dígitos |
| `municipio_ibge` | opcional; se preenchido, código IBGE de 7 dígitos |
| `data_emissao` | `YYYY-MM-DD` ou `YYYY-MM-DD HH:MM:SS` |
| `valor_servico` | numérico, usa `,` como separador decimal |

---

## Banco de dados

Tabelas criadas por `BancoDeDados.sql` (banco `sistema`):

- **`importacoes`** — um registro por upload processado (totais, ISS total, status).
- **`prestadores`** / **`tomadores`** — cadastro deduplicado por CNPJ/CPF (`UNIQUE KEY`, upsert via `ON DUPLICATE KEY`).
- **`servicos`** — tabela de regras: código LC116, NBS, indOp, cClassTrib e alíquota. É nela que o `ClassificadorServico` busca.
- **`notas`** — uma linha por nota fiscal importada, com valor, base de cálculo, alíquota aplicada e ISS calculado.
- **`erros_importacao`** — uma linha por erro de parsing/validação, vinculada à importação.

---

## Como estartar o projeto

1. **Banco de dados** — importe `BancoDeDados.sql` no MySQL/MariaDB:
   ```bash
   mysql -u root -p < BancoDeDados.sql
   ```
   Isso cria o banco `sistema` (em `utf8mb4`) com as tabelas acima.
2. **Conexão** — confira usuário, senha e nome do banco em `src/conexao.php` (padrão: `localhost` / `root` / senha vazia / banco `sistema`).
3. **Dependências PHP** — instale via Composer (necessário para o Swagger):
   ```bash
   composer install
   ```
4. **Subir o servidor** — a partir da raiz do projeto (`ISS_back-main/`), use o servidor embutido do PHP:
   ```bash
   php -S localhost:8000
   ```
   (ou publique a pasta em Apache/XAMPP/WAMP).
5. **Testar a API**:
   ```text
   http://localhost:8000/src/api/health.php
   ```
   deve responder um JSON confirmando que está no ar.
6. **Usar o frontend de exemplo**:
   ```text
   http://localhost:8000/src/index.php
   ```
7. **Antes de usar em produção**, cadastre na tabela `servicos` as regras oficiais aplicáveis ao seu município — o registro de exemplo (`01.01`) é apenas demonstrativo.

Exemplo de chamada direta ao endpoint de importação via `curl`:

```bash
curl -X POST \
  -F "arquivo=@notas_teste_iss.txt" \
  http://localhost:8000/src/api/importar.php
```

> Se o projeto for hospedado em um subdiretório do Apache/XAMPP (ex.: `C:\xampp\htdocs\ImportadorFiscalISS`), as URLs mudam de acordo — veja a seção [Correção de caminho no Apache/XAMPP](#correção-de-caminho-no-apachexampp) mais abaixo.

---

## Documentação da API (Swagger / OpenAPI)

A API é documentada com **OpenAPI 3**, usando anotações [`zircote/swagger-php`](https://github.com/zircote/swagger-php) direto nos arquivos de `src/api/`.

### Como funciona

| Arquivo | Papel |
|---|---|
| `src/api/openapi.php` | Anotações globais: `@OA\Info`, `@OA\Server`, `@OA\Tag` e os `@OA\Schema` reutilizáveis (`ImportacaoResultado`, `ErroLinha`, `RespostaErro`). |
| `src/api/health.php` | Anotação `@OA\Get` descrevendo o endpoint de healthcheck. |
| `src/api/importar.php` | Anotação `@OA\Post` descrevendo o upload (`multipart/form-data`) e as respostas possíveis (200/400/405/500). |
| `src/api/gerar-swagger.php` | Faz o *scan* de `src/` e monta o contrato OpenAPI em JSON. |
| `src/api/docs.html` | Carrega o Swagger UI (via CDN) e aponta para `gerar-swagger.php` como fonte do contrato. |

Ou seja: a documentação **nasce do próprio código**. Sempre que um endpoint muda (novo parâmetro, novo status de resposta, etc.), basta atualizar a anotação `@OA\...` correspondente — não existe um arquivo `.yaml` separado para ficar desatualizado.

### Como visualizar

1. Rode `composer install` (uma vez), para baixar o `zircote/swagger-php`.
2. Acesse no navegador:
   ```text
   http://localhost:8000/src/api/docs.html
   ```
3. A página carrega o Swagger UI, que busca `gerar-swagger.php`, que por sua vez escaneia o código e devolve o `openapi.json` na hora. Dá para testar os endpoints direto por ali ("Try it out").

### Gerando um `openapi.json` estático (opcional)

Para não escanear o código a cada request em produção, gere o arquivo uma vez (ex: no build/deploy) e sirva-o como estático:

```bash
php src/api/gerar-swagger.php > src/api/openapi.json
```

Depois, troque a `url` em `docs.html` de `gerar-swagger.php` para `openapi.json`.

> **Nota de compatibilidade (PHP 7.4):** conforme `README_SWAGGER_CORRIGIDO.md`, houve um ajuste porque o Swagger UI estava recebendo apenas `{ "openapi": "3.0.0" }` — o analisador de anotações não conseguia montar corretamente os endpoints em PHP 7.4. `gerar-swagger.php` foi corrigido para retornar diretamente uma especificação OpenAPI 3.0 válida e completa (`info`, `servers`, `tags`, `paths`, `components.schemas`, além dos endpoints `GET /health.php` e `POST /importar.php`). O restante da lógica da API não foi alterado.

---

## Endpoints

| Método | Endpoint | Descrição |
|---|---|---|
| `GET` | `/src/api/health.php` | Healthcheck da API |
| `POST` | `/src/api/importar.php` | Upload e processamento do TXT (campo `arquivo`) |
| `GET` | `/src/api/docs.html` | Documentação interativa (Swagger UI) |
| `GET` | `/src/api/gerar-swagger.php` | Contrato OpenAPI em JSON |

### Resposta de sucesso (`POST /importar.php`)

```json
{
    "sucesso": true,
    "mensagem": "Arquivo processado com sucesso.",
    "dados": {
        "importacao_id": 1,
        "arquivo": "notas_teste_iss.txt",
        "status": "PROCESSADA",
        "total_linhas": 5,
        "total_processadas": 5,
        "total_erros": 0,
        "iss_total": 1537.54,
        "erros": []
    }
}
```

Se alguma linha for inválida, a API continua processando as demais e registra as inválidas em `erros_importacao`; o `status` retornado é `PROCESSADA_COM_ERROS`.

### Consumindo a API de qualquer frontend

```javascript
const formData = new FormData();
formData.append('arquivo', arquivo);

fetch('http://localhost:8000/src/api/importar.php', {
    method: 'POST',
    body: formData
});
```

Não defina manualmente `Content-Type` ao usar `FormData` — o navegador cria o `multipart/form-data` com o boundary correto.

### CORS

Por padrão (`src/.htaccess` e nos endpoints PHP), a API permite:

```http
Access-Control-Allow-Origin: *
```

Isso facilita testes locais. **Em produção, substitua `*` pelo domínio real do frontend.**

### Correção de caminho no Apache/XAMPP

Se o projeto estiver em `C:\xampp\htdocs\ImportadorFiscalISS` e for acessado como `http://localhost/ImportadorFiscalISS/src/index.php`, a API correspondente deve ser chamada em `http://localhost/ImportadorFiscalISS/src/api/importar.php` (o frontend de exemplo já vem configurado com esse caminho). Primeiro teste recomendado: `http://localhost/ImportadorFiscalISS/src/api/health.php`, que deve retornar JSON. A API também captura erros do PHP e tenta devolvê-los em JSON, evitando que erros comuns apareçam para o frontend como uma página HTML iniciada por `<!DOCTYPE`.

---

## Observação fiscal

O registro de exemplo `01.01` na tabela `servicos` é apenas demonstrativo. A aplicação foi construída para manter NBS, indOp e cClassTrib associados ao serviço, mas esses campos não devem ser preenchidos com valores presumidos. Para produção, carregue os dados oficiais da base normativa/documental utilizada pelo seu município. O cadastro de `servicos` do SQL contém somente um registro demonstrativo para teste — as regras devem ser carregadas da base oficial correspondente ao município e à legislação aplicável, e o código não deve assumir uma alíquota fixa para todos os serviços.

---

## Resumo dos demais READMEs do repositório

Esta seção resume, em poucas linhas, o conteúdo de cada README adicional presente no projeto — os detalhes completos já foram incorporados nas seções acima.

- **`README_API.md`** — Documentação de referência focada só na API: separa as responsabilidades entre frontend e backend/API, detalha o endpoint `POST /importar.php` (request, resposta de sucesso, resposta com erros), a regra de responsabilidade de cada classe (API, `ImportadorTxt`, `ClassificadorServico`, `CalculadoraIss`, Banco), como consumir a API de outro frontend (Angular/React/Vue), a política de CORS e a observação sobre caminhos ao rodar em Apache/XAMPP.
- **`README_SWAGGER_CORRIGIDO.md`** — Explica a correção feita no Swagger para funcionar em PHP 7.4: antes o `gerar-swagger.php` devolvia só `{ "openapi": "3.0.0" }` pois o parser de anotações não montava os endpoints corretamente nessa versão do PHP; a correção faz o arquivo retornar diretamente uma especificação OpenAPI 3.0 completa (info, servers, tags, paths, schemas), sem alterar o restante da lógica da API. Lista também os endereços de Swagger UI, especificação OpenAPI, healthcheck e importação.
- **`README_original.md`** — Histórico do projeto anterior (`ArquivosParaBanco`), o exercício didático do SENAI do qual este projeto evoluiu: uma aplicação PHP simples para importar **clientes** (não notas fiscais) de um `.txt` para uma tabela `clientes` no MySQL, com validação linha a linha. Documenta requisitos (PHP 7.4+ com `mysqli`, MySQL/MariaDB), estrutura de pastas da versão antiga e os passos originais de instalação (criar banco, configurar `conexao.php`, subir servidor).
- **`frontend/README.md`** — Nota curta: o diretório `frontend/` representa conceitualmente o frontend do sistema; o exemplo funcional de fato está em `src/index.php`, e toda comunicação com o backend deve ocorrer exclusivamente via HTTP contra `src/api/importar.php`.