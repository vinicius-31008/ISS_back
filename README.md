# Importador Fiscal ISS — TXT → MySQL → ISS

API PHP que recebe um arquivo `.txt` com notas fiscais, classifica o serviço prestado (LC 116), calcula o ISS devido e grava tudo no MySQL. Os dados são separados por **empresa**: cada usuário pertence a uma empresa e só enxerga as importações dela. Inclui documentação interativa via **Swagger / OpenAPI**.

> **Primeiro acesso:** com o banco recém-criado, entre com **`admin@iss.local`** / **`admin123`** e troque a senha. Veja [Primeiro acesso](#primeiro-acesso).

> Este projeto é uma evolução do exercício didático original de importação de clientes (SENAI). O histórico dessa versão anterior está preservado em [`README_original.md`](README_original.md).

---

## Sumário

- [Estrutura do projeto](#estrutura-do-projeto)
- [Como cada parte funciona](#como-cada-parte-funciona)
- [Fluxo de uma importação](#fluxo-de-uma-importação)
- [Formato do arquivo TXT](#formato-do-arquivo-txt)
- [Banco de dados](#banco-de-dados)
- [Instalação](#instalação)
- [Primeiro acesso](#primeiro-acesso)
- [Empresas, usuários e permissões](#empresas-usuários-e-permissões)
- [Documentação da API (Swagger / OpenAPI)](#documentação-da-api-swagger--openapi)
- [Endpoints](#endpoints)
- [Histórico de correções](#histórico-de-correções)
- [Observação fiscal](#observação-fiscal)

---

## Estrutura do projeto

```text
ISS_back-main/
├── composer.json                  # Dependências PHP (swagger-php para gerar a doc)
├── BancoDeDados.sql                # Script de criação do schema MySQL
├── migracoes/                      # Scripts para atualizar bancos já existentes (rodar em ordem)
│   ├── 001_chave_unica_notas.sql
│   ├── 002_empresas_usuarios.sql
│   ├── 003_perfil_usuario.sql
│   └── 004_trocar_senha.sql
├── scripts/
│   └── criar_usuario.php           # CLI: cria empresa + usuário (alternativa ao usuário padrão)
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
    ├── .htaccess                   # CORS, repasse do cabeçalho Authorization e proteção de listagem (Apache)
    │
    ├── api/                        # Camada HTTP (o que fica exposto na web)
    │   ├── health.php               # GET  — healthcheck da API
    │   ├── login.php                # POST — e-mail + senha → token
    │   ├── trocar-senha.php         # POST — troca a própria senha
    │   ├── logout.php               # POST — invalida o token
    │   ├── importar.php             # POST — recebe o TXT, processa e retorna JSON
    │   ├── historico.php            # GET  — histórico das importações (lista e detalhe)
    │   ├── empresas.php             # CRUD de empresas (ADMIN)
    │   ├── usuarios.php             # CRUD de usuários (ADMIN)
    │   ├── requisicao.php           # Funções comuns de empresas.php / usuarios.php / trocar-senha.php
    │   ├── openapi.php              # Anotações OpenAPI globais (Info/Servers/Schemas)
    │   ├── gerar-swagger.php        # Gera o contrato openapi.json a partir das anotações
    │   └── docs.html                # Swagger UI, consome gerar-swagger.php
    │
    └── backend/                    # Regras de negócio (não é acessado diretamente pela web)
        ├── ImportadorTxt.php        # Orquestra a importação linha a linha
        ├── HistoricoImportacoes.php # Consultas do histórico de importações
        ├── ClassificadorServico.php # Busca a regra de serviço (LC116) no banco
        ├── CalculadoraIss.php       # Calcula base de cálculo e valor do ISS
        ├── Autenticacao.php         # Login, validação do token, troca de senha
        ├── UsuarioPadrao.php        # Credenciais do administrador de primeiro acesso
        ├── Cadastro.php             # Base dos cadastros (validação, erros com status HTTP)
        ├── CadastroEmpresas.php     # Regras do CRUD de empresas
        └── CadastroUsuarios.php     # Regras do CRUD de usuários
```

**Regra de organização:** tudo que está em `src/api/` é um endpoint público (arquivo acessível diretamente por URL). Tudo que está em `src/backend/` é lógica interna, chamada pelos endpoints, e nunca deve ser acessada diretamente pelo navegador.

---

## Como cada parte funciona

### `src/api/health.php`
Endpoint `GET` sem dependência de banco. Serve só para o frontend (ou qualquer client) confirmar que a API está no ar antes de tentar enviar um arquivo grande.

### `src/api/login.php`, `trocar-senha.php`, `logout.php` e `src/backend/Autenticacao.php`
O login troca e-mail e senha por um **token** válido por 8 horas, enviado nas demais rotas no cabeçalho `Authorization: Bearer <token>`. O banco guarda só o hash SHA-256 do token, e as senhas com `password_hash` (bcrypt). Usuário ou empresa desativados não conseguem entrar, e os tokens abertos deles param de funcionar na hora. Se o banco não tiver nenhum usuário, o login cria o administrador padrão (veja [Primeiro acesso](#primeiro-acesso)).

### `src/api/importar.php`
Endpoint `POST` principal. Responsabilidades:
1. Configura headers de resposta (`Content-Type: application/json`) e CORS.
2. Converte erros e exceptions do PHP em respostas JSON padronizadas (evita devolver uma página HTML de erro para o front).
3. Exige o token (`401` sem login; `403` com senha provisória ainda não trocada).
4. Valida a requisição: método HTTP, presença do arquivo, extensão `.txt`, se é realmente um upload válido, se não está vazio.
5. Instancia `ImportadorTxt` (injetando a conexão, o `ClassificadorServico` e a `CalculadoraIss`) e chama `processar()` com a empresa e o usuário do token.
6. Formata o resultado em JSON e responde.

### `src/api/historico.php`
Endpoint `GET` de consulta, funciona como o histórico de transações da API. Mostra **só as importações da empresa do usuário logado**:
- **sem `id`** — lista paginada das importações (mais recentes primeiro), com filtros opcionais por `status`, `arquivo`, `data_inicio` e `data_fim`, e o ISS total de tudo que atende aos filtros;
- **com `id`** — detalhe de uma importação, com as notas gravadas (prestador, tomador, serviço, valores e ISS) e os erros de linha.

Cada importação informa o `usuario` que a fez. Valida os parâmetros (400 se inválidos, 404 se o `id` não existir ou for de outra empresa) e delega as consultas para `HistoricoImportacoes`. Em erro interno, responde só uma mensagem genérica; o detalhe técnico vai para o log do PHP.

### `src/api/empresas.php` e `src/api/usuarios.php`
CRUDs (`GET`/`POST`/`PUT`/`DELETE`) restritos ao perfil `ADMIN`. A validação e as regras ficam em `CadastroEmpresas` e `CadastroUsuarios` (ambas estendem `Cadastro`). Veja [Empresas, usuários e permissões](#empresas-usuários-e-permissões).

### `src/backend/HistoricoImportacoes.php`
Monta as consultas do histórico com prepared statements (filtros dinâmicos, paginação via `LIMIT/OFFSET`) e formata a resposta com os tipos corretos (números como número, tomador `null` quando a nota não tem tomador).

### `src/backend/ImportadorTxt.php`
O coração da regra de negócio. Para cada linha do TXT:
- faz o parse dos 10 campos (`str_getcsv` com `;`);
- valida formato, tamanho de CNPJ/CPF, valor, data (precisa ser uma data existente) e código IBGE;
- rejeita a linha se a mesma nota (prestador + número + série) já apareceu antes no arquivo;
- pergunta ao `ClassificadorServico` se o `codigo_lc116` está cadastrado e ativo;
- pede à `CalculadoraIss` o cálculo do imposto;
- grava (ou atualiza, via `ON DUPLICATE KEY`) o prestador, o tomador e a nota;
- linhas com erro **não interrompem o processo** — são coletadas e registradas em `erros_importacao`, e a importação termina com status `PROCESSADA_COM_ERROS`.

Toda a operação roda dentro de uma transação (`mysqli_begin_transaction` / `commit` / `rollback`), com um `SAVEPOINT` por linha:

- se uma linha falha no meio (ex.: o prestador já foi gravado, mas a nota não), a linha é desfeita até o savepoint e nada dela fica no banco;
- se algo inesperado quebrar (não um erro de linha, mas uma falha real), a transação inteira é desfeita e a tentativa fica registrada em `importacoes` com status `ERRO`.

**Reimportação:** a nota é identificada por empresa + prestador + número + série. Reenviar uma nota que já existe **atualiza** o registro (valores, data, tomador, serviço e a importação a que ela pertence) em vez de criar uma duplicata. A mesma nota importada por duas empresas diferentes gera dois registros independentes.

### `src/backend/ClassificadorServico.php`
Classe pequena e única responsabilidade: dado um `codigo_lc116`, busca na tabela `servicos` a descrição, NBS, indicador de operação, cClassTrib e a alíquota cadastrada.

### `src/backend/CalculadoraIss.php`
Só matemática: `base_calculo = valor_servico` e `valor_iss = base_calculo * aliquota / 100`, com validação de valores negativos/alíquota fora da faixa 0–100.

### `src/conexao.php`
Abre a conexão `mysqli` (`mysqli_report` em modo estrito, para que falhas de SQL virem exceções em vez de warnings silenciosos).

### `src/index.php`
Cliente de exemplo em HTML + JavaScript puro (sem framework). Faz login (e a troca de senha, quando é provisória), testa o `health.php`, envia o arquivo via `FormData` para `importar.php` com o token e renderiza o resultado. Serve como referência de como qualquer frontend (Angular/React/Vue/etc.) deve consumir a API.

### `src/processar.php`
Endpoint antigo (da versão anterior do projeto). Mantido apenas para responder `410 Gone` com uma mensagem apontando para o endpoint atual, evitando quebrar integrações antigas silenciosamente.

---

## Fluxo de uma importação

```text
Frontend (index.php ou outro client)
   |
   |  POST /src/api/login.php  →  token
   |
   |  POST multipart/form-data (campo "arquivo")
   |  Authorization: Bearer <token>
   v
src/api/importar.php
   |
   +-- valida o token → empresa e usuário
   +-- valida requisição e arquivo
   |
   v
ImportadorTxt::processar()
   |
   +-- para cada linha do TXT:
   |     +-- SAVEPOINT
   |     +-- valida os 10 campos e duplicidade no arquivo
   |     +-- ClassificadorServico::buscar(codigo_lc116)
   |     +-- CalculadoraIss::calcular(valor, aliquota)
   |     +-- grava prestador / tomador / nota da empresa (insere ou atualiza)
   |     +-- (em erro) ROLLBACK TO SAVEPOINT, registra em erros_importacao,
   |         segue para a próxima linha
   |
   +-- finaliza a importação (totais, status) e faz COMMIT
   +-- (falha geral) ROLLBACK e registra a importação com status ERRO
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
| `data_emissao` | `YYYY-MM-DD` ou `YYYY-MM-DD HH:MM:SS`; precisa ser uma data existente (`2026-02-31` é rejeitada). Sem hora, grava `00:00:00` |
| `valor_servico` | até 2 casas decimais. Aceita `1000,50`, `1.000,50` (ponto como milhar) e `1000.50` (ponto como decimal). `1.000` sem vírgula é lido como mil |
| `numero` + `serie` | não podem se repetir para o mesmo prestador dentro do arquivo |

---

## Banco de dados

Tabelas criadas por `BancoDeDados.sql` (banco `sistema`):

- **`empresas`** — dona dos dados. CNPJ único; `ativo = 0` bloqueia todos os usuários dela.
- **`usuarios`** — cada um pertence a uma empresa. E-mail único, senha em bcrypt, `perfil` (`ADMIN`/`USUARIO`) e `trocar_senha` (senha provisória).
- **`sessoes`** — tokens de login (só o hash SHA-256) com data de expiração.
- **`importacoes`** — um registro por upload (empresa, usuário que importou, totais, ISS total, status `PROCESSADA`, `PROCESSADA_COM_ERROS` ou `ERRO`).
- **`prestadores`** / **`tomadores`** — cadastrados **por empresa**, deduplicados por empresa + CNPJ/CPF (`UNIQUE KEY`, upsert via `ON DUPLICATE KEY`). Assim a importação de uma empresa não altera o que outra vê.
- **`servicos`** — tabela de regras: código LC116, NBS, indOp, cClassTrib e alíquota. É nela que o `ClassificadorServico` busca. Compartilhada entre as empresas.
- **`notas`** — uma linha por nota fiscal, única por `(prestador_id, numero, serie)` (como o prestador é por empresa, a chave já separa as empresas), com valor, base de cálculo, alíquota aplicada e ISS calculado. `importacao_id` aponta para a última importação que gravou a nota.
- **`erros_importacao`** — uma linha por erro de parsing/validação, vinculada à importação.

---

## Instalação

Requisitos: PHP 8.1+ com a extensão `mysqli` habilitada no `php.ini`, e MySQL 8 / MariaDB.

1. Importe `BancoDeDados.sql` no MySQL/MariaDB.
   - **Já tem o banco criado com uma versão anterior?** Faça backup e rode, em ordem e uma única vez cada, as migrações que ainda não aplicou:
     - `migracoes/001_chave_unica_notas.sql` — troca a chave única de `notas` e **apaga notas duplicadas por reimportação, mantendo a mais recente** (o script traz uma consulta para conferir antes);
     - `migracoes/002_empresas_usuarios.sql` — cria empresas, usuários e sessões e atribui os dados existentes a uma "empresa padrão" (CNPJ `00000000000000`);
     - `migracoes/003_perfil_usuario.sql` — perfil `ADMIN`/`USUARIO`;
     - `migracoes/004_trocar_senha.sql` — troca de senha obrigatória.
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
6. Abra `http://localhost:8000/src/index.php` para usar o frontend de exemplo. Com o servidor embutido do PHP, troque o campo **URL da API** para `/src/api/importar.php` (o valor padrão é o caminho do XAMPP).
7. Faça o [primeiro acesso](#primeiro-acesso).
8. **Antes de usar em produção**, cadastre na tabela `servicos` as regras oficiais aplicáveis ao seu município — o registro de exemplo (`01.01`) é apenas demonstrativo.

---

## Primeiro acesso

Com o banco recém-criado (**nenhum usuário cadastrado**), o primeiro login cria automaticamente um administrador padrão:

| E-mail | Senha | Perfil |
|---|---|---|
| `admin@iss.local` | `admin123` | `ADMIN` |

1. Entre com essas credenciais (pela tela `src/index.php`, pelo Swagger ou por `POST /src/api/login.php`).
2. **Troque a senha.** Até a troca, o token só serve para `POST /src/api/trocar-senha.php`; todas as outras rotas respondem `403`. A tela de exemplo mostra o formulário de troca sozinha.
   ```bash
   curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
     -d '{"senha_atual": "admin123", "nova_senha": "UmaSenhaForte!"}' \
     http://localhost:8000/src/api/trocar-senha.php
   ```
3. O administrador fica numa empresa chamada "Empresa padrão" (CNPJ `00000000000000`). Ajuste a razão social e o CNPJ dela com `PUT /src/api/empresas.php?id=1`.
4. Crie as empresas e os usuários reais por `empresas.php` e `usuarios.php`.

Por que isso é seguro mesmo com a senha escrita no código:

- a senha `admin123` não dá acesso a nada além da própria troca de senha;
- o usuário padrão **só é criado enquanto o banco não tem nenhum usuário**. Depois que existir qualquer usuário, ele nunca é recriado.

As credenciais ficam em [`src/backend/UsuarioPadrao.php`](src/backend/UsuarioPadrao.php). Para usar outras, altere as constantes lá **antes** do primeiro acesso.

**Alternativa sem o usuário padrão:** crie o primeiro administrador pela linha de comando antes de qualquer login. Como o banco deixa de estar vazio, o usuário padrão não é criado:

```bash
php scripts/criar_usuario.php --cnpj=12345678000190 --empresa="Minha Empresa Ltda" \
    --nome="Fulano de Tal" --email=fulano@exemplo.com --senha=SenhaForte123 --perfil=ADMIN
```

---

## Empresas, usuários e permissões

Cada **usuário pertence a uma empresa**, e tudo o que ele importa fica vinculado a ela. O histórico de uma empresa nunca mostra dados de outra.

| Perfil | Pode |
|---|---|
| `ADMIN` | tudo, inclusive gerenciar empresas e usuários de qualquer empresa (`empresas.php`, `usuarios.php`) |
| `USUARIO` | importar arquivos e consultar o histórico da própria empresa |

Regras dos cadastros:

- **`DELETE` desativa, não apaga** (empresas têm notas fiscais; o histórico mostra quem importou). Para reativar, envie `PUT` com `{"ativo": true}`.
- Desativar uma empresa bloqueia na hora todos os usuários dela.
- Trocar a senha ou desativar um usuário encerra as sessões abertas dele.
- O admin não pode desativar a si mesmo, remover o próprio perfil `ADMIN` nem desativar a própria empresa, para o sistema não ficar sem administrador.
- A senha nunca é devolvida pela API, nem como hash.

Campos aceitos, exemplos com `curl` e todos os códigos de resposta estão em [`README_API.md`](README_API.md).

---

## Documentação da API (Swagger / OpenAPI)

A API é documentada com **OpenAPI 3**, usando anotações [`zircote/swagger-php`](https://github.com/zircote/swagger-php) direto nos arquivos de `src/api/`.

### Como funciona

| Arquivo | Papel |
|---|---|
| `src/api/openapi.php` | Anotações globais: `@OA\Info`, `@OA\Server`, `@OA\Tag` e os `@OA\Schema` reutilizáveis (`ImportacaoResultado`, `ErroLinha`, `RespostaErro`). |
| `src/api/health.php` | Anotação `@OA\Get` descrevendo o endpoint de healthcheck. |
| `src/api/importar.php` | Anotação `@OA\Post` descrevendo o upload (`multipart/form-data`) e as respostas possíveis (200/400/405/500). |
| `src/api/gerar-swagger.php` | Devolve o contrato OpenAPI em JSON, montado **manualmente** como array PHP (veja [`README_SWAGGER_CORRIGIDO.md`](README_SWAGGER_CORRIGIDO.md)). |
| `src/api/docs.html` | Carrega o Swagger UI (via CDN) e aponta para `gerar-swagger.php` como fonte do contrato. |

> **Atenção:** como o scan de anotações não funciona no PHP 7.4, o Swagger exibido vem do array em `gerar-swagger.php`, não das anotações `@OA`. Ao criar ou alterar um endpoint, **atualize `gerar-swagger.php`** — é ele que aparece em `docs.html`. `historico.php`, `login.php`, `trocar-senha.php`, `logout.php`, `empresas.php` e `usuarios.php` estão documentados apenas lá.
>
> Para testar as rotas protegidas no Swagger: faça o login em `login.php`, clique em **Authorize** e cole o `token` devolvido.

### Como visualizar

1. Rode `composer install` (uma vez), para baixar o `zircote/swagger-php`.
2. Acesse no navegador:
   ```text
   http://localhost:8000/src/api/docs.html
   ```
3. A página carrega o Swagger UI, que busca o contrato em `gerar-swagger.php`. Dá para testar os endpoints direto por ali ("Try it out").

### Gerando um `openapi.json` estático (opcional)

Para não escanear o código a cada request em produção, gere o arquivo uma vez (ex: no build/deploy) e sirva-o como estático:

```bash
php src/api/gerar-swagger.php > src/api/openapi.json
```

Depois, troque a `url` em `docs.html` de `gerar-swagger.php` para `openapi.json`.

---

## Endpoints

| Método | Endpoint | Acesso | Descrição |
|---|---|---|---|
| `GET` | `/src/api/health.php` | público | Healthcheck da API |
| `POST` | `/src/api/login.php` | público | E-mail + senha → token |
| `POST` | `/src/api/trocar-senha.php` | logado | Troca a própria senha (única rota liberada com senha provisória) |
| `POST` | `/src/api/logout.php` | logado | Invalida o token |
| `POST` | `/src/api/importar.php` | logado | Upload e processamento do TXT (campo `arquivo`) |
| `GET` | `/src/api/historico.php` | logado | Lista paginada das importações da empresa (filtros: `status`, `arquivo`, `data_inicio`, `data_fim`, `pagina`, `por_pagina`) |
| `GET` | `/src/api/historico.php?id={id}` | logado | Detalhe de uma importação, com notas e erros de linha |
| `GET` `POST` `PUT` `DELETE` | `/src/api/empresas.php` | `ADMIN` | CRUD de empresas (`?id=` no detalhe, `PUT` e `DELETE`) |
| `GET` `POST` `PUT` `DELETE` | `/src/api/usuarios.php` | `ADMIN` | CRUD de usuários (`?id=` no detalhe, `PUT` e `DELETE`) |
| `GET` | `/src/api/docs.html` | público | Documentação interativa (Swagger UI) |
| `GET` | `/src/api/gerar-swagger.php` | público | Contrato OpenAPI em JSON |

Rotas "logado" e `ADMIN` exigem o cabeçalho `Authorization: Bearer <token>`.

Login e importação:

```bash
# login: copie o "token" da resposta
curl -X POST -H "Content-Type: application/json" \
  -d '{"email": "fulano@exemplo.com", "senha": "SenhaForte123"}' \
  http://localhost:8000/src/api/login.php

curl -X POST \
  -H "Authorization: Bearer <token>" \
  -F "arquivo=@notas_teste_iss.txt" \
  http://localhost:8000/src/api/importar.php
```

Consultando o histórico:

```bash
# importações com erro de linha em outubro/2026, 10 por página
curl -H "Authorization: Bearer <token>" \
  "http://localhost:8000/src/api/historico.php?status=PROCESSADA_COM_ERROS&data_inicio=2026-10-01&data_fim=2026-10-31&por_pagina=10"

# detalhe da importação 1
curl -H "Authorization: Bearer <token>" "http://localhost:8000/src/api/historico.php?id=1"
```

Criando uma empresa e um usuário (token de `ADMIN`):

```bash
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"cnpj": "11.222.333/0001-81", "razao_social": "Cliente Novo Ltda"}' \
  http://localhost:8000/src/api/empresas.php

curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"empresa_id": 2, "nome": "Maria", "email": "maria@cliente.com", "senha": "SenhaMaria123"}' \
  http://localhost:8000/src/api/usuarios.php
```

Mais detalhes de request/response (incluindo exemplo de resposta com erros de linha e configuração de CORS) estão em [`README_API.md`](README_API.md).

---

## Histórico de correções

Correções no `ImportadorTxt` e no schema:

| Problema | Antes | Agora |
|---|---|---|
| Hora da data de emissão | `2026-08-20` era gravada com a hora atual do servidor | Gravada como `2026-08-20 00:00:00` |
| Datas impossíveis | `2026-02-31` virava `2026-03-03` sem aviso | Linha rejeitada com erro |
| Reimportação | Reenviar o arquivo duplicava todas as notas | A nota existente é atualizada (chave `prestador + numero + serie`) |
| Mesma nota de prestadores diferentes | Colidiam: a segunda sobrescrevia a primeira e o ISS era somado duas vezes | Notas distintas, cada uma com seu prestador |
| Nota repetida no mesmo arquivo | Sobrescrevia a anterior e somava o ISS em dobro | Linha rejeitada, apontando a linha original |
| Valor com milhar (`1.000,50`) | Rejeitado como inválido | Aceito |
| Falha no meio de uma linha | Prestador/tomador ficavam gravados mesmo com a linha rejeitada | `SAVEPOINT` por linha desfaz tudo da linha |
| Falha geral da importação | O rollback apagava qualquer rastro da tentativa | Registrada em `importacoes` com status `ERRO` |
| Acesso à API | Qualquer pessoa importava e via todo o histórico | Login com token; cada usuário só vê os dados da própria empresa |
| Mesma nota em empresas diferentes | (não existia o conceito de empresa) | Registros independentes; prestadores e tomadores são cadastrados por empresa |

Bancos existentes precisam das migrações de `migracoes/` (veja [Instalação](#instalação)).

---

## Observação fiscal

O registro de exemplo `01.01` na tabela `servicos` é apenas demonstrativo. A aplicação foi construída para manter NBS, indOp e cClassTrib associados ao serviço, mas esses campos não devem ser preenchidos com valores presumidos. Para produção, carregue os dados oficiais da base normativa/documental utilizada pelo seu município.
