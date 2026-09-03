# Importador Fiscal ISS — TXT → MySQL → ISS

API PHP que recebe um arquivo `.txt` com notas fiscais, classifica o serviço prestado (LC 116), calcula o ISS devido e grava tudo no MySQL. Inclui documentação interativa via **Swagger / OpenAPI**.

> Este projeto é uma evolução do exercício didático original de importação de clientes (SENAI). O histórico dessa versão anterior está preservado em [`README_original.md`](README_original.md).

---

## Sumário

- [Estrutura do projeto](#estrutura-do-projeto)
- [Como cada parte funciona](#como-cada-parte-funciona)
- [Fluxo de uma importação](#fluxo-de-uma-importação)
- [Formato do arquivo TXT](#formato-do-arquivo-txt)
- [Banco de dados](#banco-de-dados)
- [Instalação](#instalação)
- [Documentação da API (Swagger / OpenAPI)](#documentação-da-api-swagger--openapi)
- [Endpoints](#endpoints)
- [Observação fiscal](#observação-fiscal)

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

## Instalação

1. Importe `BancoDeDados.sql` no MySQL/MariaDB.
2. Confira usuário, senha e nome do banco em `src/conexao.php`.
3. Instale as dependências PHP (necessário para o Swagger — veja a seção abaixo):
   ```bash
   composer install
   ```
4. Coloque o projeto no seu servidor (Apache/XAMPP/WAMP) ou suba com o servidor embutido do PHP a partir da raiz do projeto:
   ```bash
   php -S localhost:8000
   ```
5. Teste a API: `http://localhost:8000/src/api/health.php` deve responder um JSON.
6. Abra `http://localhost:8000/src/index.php` para usar o frontend de exemplo.
7. **Antes de usar em produção**, cadastre na tabela `servicos` as regras oficiais aplicáveis ao seu município — o registro de exemplo (`01.01`) é apenas demonstrativo.

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

---

## Endpoints

| Método | Endpoint | Descrição |
|---|---|---|
| `GET` | `/src/api/health.php` | Healthcheck da API |
| `POST` | `/src/api/importar.php` | Upload e processamento do TXT (campo `arquivo`) |
| `GET` | `/src/api/docs.html` | Documentação interativa (Swagger UI) |
| `GET` | `/src/api/gerar-swagger.php` | Contrato OpenAPI em JSON |

Exemplo de chamada ao endpoint principal:

```bash
curl -X POST \
  -F "arquivo=@notas_teste_iss.txt" \
  http://localhost:8000/src/api/importar.php
```

Mais detalhes de request/response (incluindo exemplo de resposta com erros de linha e configuração de CORS) estão em [`README_API.md`](README_API.md).

---

## Observação fiscal

O registro de exemplo `01.01` na tabela `servicos` é apenas demonstrativo. A aplicação foi construída para manter NBS, indOp e cClassTrib associados ao serviço, mas esses campos não devem ser preenchidos com valores presumidos. Para produção, carregue os dados oficiais da base normativa/documental utilizada pelo seu município.
