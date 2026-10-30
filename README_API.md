# Importador Fiscal ISS — Backend API

## Arquitetura

O projeto foi separado em duas responsabilidades:

```text
FRONTEND
   |
   | multipart/form-data
   | campo: arquivo
   v
BACKEND / API
   |
   +-- valida TXT
   +-- lê registros
   +-- classifica serviço
   +-- consulta NBS / indOp / cClassTrib / alíquota
   +-- calcula ISS
   +-- grava no MySQL
   +-- retorna JSON
```

O backend não depende do HTML para processar o arquivo.

## Empresas, usuários e autenticação

Cada **usuário** pertence a uma **empresa**. Tudo o que é importado fica vinculado à empresa do usuário que fez a importação:

- `importacoes.empresa_id` (e `importacoes.usuario_id`, quem importou);
- `prestadores` e `tomadores` são cadastrados por empresa (chave única `empresa_id + CNPJ`);
- notas e erros de linha pertencem à empresa por meio do prestador e da importação.

Assim, a mesma nota pode ser importada por duas empresas sem colidir, e o histórico de uma empresa nunca mostra dados de outra.

### Perfis

| Perfil | Pode |
|---|---|
| `ADMIN` | tudo, inclusive gerenciar empresas e usuários (`empresas.php`, `usuarios.php`) de qualquer empresa |
| `USUARIO` | importar e consultar o histórico da própria empresa |

### Primeiro acesso: administrador padrão

Enquanto o banco **não tiver nenhum usuário**, o login cria automaticamente um administrador padrão, definido em [src/backend/UsuarioPadrao.php](src/backend/UsuarioPadrao.php):

| E-mail | Senha | Perfil |
|---|---|---|
| `admin@iss.local` | `admin123` | `ADMIN` |

Ele fica numa empresa "Empresa padrão" (CNPJ `00000000000000`; num banco migrado pela 002, é a mesma empresa que recebeu os dados antigos). Ajuste a razão social e o CNPJ dela depois, com `PUT /src/api/empresas.php?id=1`.

A senha é pública, por isso há duas proteções:

- **troca obrigatória**: o login devolve `usuario.trocar_senha: true` e, até a troca, todas as rotas respondem `403`, menos `trocar-senha.php`;
- **só existe no banco vazio**: depois que houver qualquer usuário, o padrão não é recriado.

```http
POST /src/api/trocar-senha.php
Authorization: Bearer <token>
Content-Type: application/json

{"senha_atual": "admin123", "nova_senha": "UmaSenhaForte!"}
```

A troca mantém a sessão atual (o mesmo token passa a ter acesso normal) e encerra as demais. Qualquer usuário pode usar essa rota para trocar a própria senha. A tela de exemplo (`src/index.php`) mostra o formulário de troca automaticamente.

### Primeiro administrador pela linha de comando (alternativa)

Em vez do usuário padrão, o primeiro `ADMIN` pode ser criado pelo script (os demais usuários e empresas ele cria pela API):

```bash
php scripts/criar_usuario.php --cnpj=12345678000190 --empresa="Empresa Exemplo Ltda" \
    --nome="Fulano de Tal" --email=fulano@exemplo.com --senha=SenhaForte123 --perfil=ADMIN
```

Se a empresa (CNPJ) já existir, `--empresa` pode ser omitido e o usuário é adicionado a ela. Sem `--perfil`, o usuário é criado como `USUARIO`.

Bancos criados antes desta versão: rode, em ordem, `migracoes/002_empresas_usuarios.sql` (os dados antigos vão para uma empresa padrão com CNPJ `00000000000000`; veja as instruções no próprio arquivo), `migracoes/003_perfil_usuario.sql` e `migracoes/004_trocar_senha.sql`.

### Login

```http
POST /src/api/login.php
Content-Type: application/json

{"email": "fulano@exemplo.com", "senha": "SenhaForte123"}
```

```json
{
    "sucesso": true,
    "dados": {
        "token": "3f9a...c21e",
        "expira_em": "2026-10-14 22:30:00",
        "usuario": { "id": 1, "nome": "Fulano de Tal", "email": "fulano@exemplo.com", "perfil": "ADMIN", "trocar_senha": false },
        "empresa": { "id": 1, "cnpj": "12345678000190", "razao_social": "Empresa Exemplo Ltda" }
    }
}
```

O token vale 8 horas. Envie-o em `importar.php` e `historico.php`:

```http
Authorization: Bearer 3f9a...c21e
```

Sem token (ou com token expirado/inválido, usuário ou empresa inativos) a API responde `401`. `POST /src/api/logout.php` com o mesmo cabeçalho invalida o token.

## Cadastro de empresas e usuários (somente ADMIN)

```http
GET    /src/api/empresas.php            lista (filtros: busca, ativo, pagina, por_pagina)
GET    /src/api/empresas.php?id={id}    detalhe
POST   /src/api/empresas.php            cria
PUT    /src/api/empresas.php?id={id}    altera só os campos enviados
DELETE /src/api/empresas.php?id={id}    desativa

GET    /src/api/usuarios.php            lista (filtros: busca, empresa_id, perfil, ativo, pagina, por_pagina)
GET    /src/api/usuarios.php?id={id}    detalhe
POST   /src/api/usuarios.php            cria
PUT    /src/api/usuarios.php?id={id}    altera só os campos enviados
DELETE /src/api/usuarios.php?id={id}    desativa

Authorization: Bearer <token de um ADMIN>
Content-Type: application/json          (POST e PUT)
```

### Exemplos

```bash
# cria uma empresa (CNPJ com ou sem máscara)
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"cnpj": "11.222.333/0001-81", "razao_social": "Cliente Novo Ltda"}' \
  http://localhost:8000/src/api/empresas.php

# cria um usuário nela (perfil padrão USUARIO)
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"empresa_id": 2, "nome": "Maria", "email": "maria@cliente.com", "senha": "SenhaMaria123"}' \
  http://localhost:8000/src/api/usuarios.php

# troca a senha (encerra as sessões abertas dela)
curl -X PUT -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"senha": "OutraSenha456"}' \
  "http://localhost:8000/src/api/usuarios.php?id=2"
```

Campos aceitos:

| Recurso | POST (obrigatórios) | Opcionais |
|---|---|---|
| empresa | `cnpj`, `razao_social` | `ativo` |
| usuário | `empresa_id`, `nome`, `email`, `senha` (8 a 72 caracteres) | `perfil` (`ADMIN`/`USUARIO`, padrão `USUARIO`), `ativo` |

No `PUT`, todos são opcionais: envie só o que muda. Campos desconhecidos (ex.: `razaosocial`) são rejeitados com `400`, para um erro de digitação não passar despercebido.

### Regras

- **DELETE desativa, não apaga.** Empresas têm importações e notas fiscais, e usuários aparecem no histórico como autores das importações. Para reativar, envie `PUT` com `{"ativo": true}`.
- Desativar uma empresa bloqueia na hora todos os usuários dela: o login falha e os tokens abertos passam a receber `401`.
- Trocar a senha ou desativar um usuário encerra as sessões abertas dele.
- O admin não pode desativar a si mesmo, remover o próprio perfil `ADMIN` nem desativar a própria empresa, para o sistema não ficar sem administrador.
- A senha nunca é devolvida, nem como hash.
- Não dá para criar nem mover usuário para uma empresa desativada.

| HTTP | Quando |
|---|---|
| `200` / `201` | sucesso (`201` no POST) |
| `400` | campo inválido, obrigatório ausente, campo desconhecido, JSON inválido, `id` ausente, operação sobre o próprio acesso |
| `401` | token ausente, inválido ou expirado |
| `403` | usuário logado não é `ADMIN`, ou está com senha provisória (troque em `trocar-senha.php`) |
| `404` | registro não encontrado |
| `405` | método não suportado |
| `409` | CNPJ ou e-mail já cadastrado |

## Endpoint de importação

```http
POST /api/importar.php
Content-Type: multipart/form-data
Authorization: Bearer <token>
```

Campo obrigatório:

```text
arquivo = arquivo.txt
```

### Exemplo com cURL

```bash
curl -X POST \
  -H "Authorization: Bearer <token>" \
  -F "arquivo=@arquivos_teste/notas_teste_iss.txt" \
  http://localhost/ImportadorFiscalISS/src/api/importar.php
```

## Resposta de sucesso

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

## Resposta com erros de linhas

A API continua processando as linhas válidas e registra as inválidas na tabela `erros_importacao`.

O status será:

```text
PROCESSADA_COM_ERROS
```

Cada linha roda dentro de um `SAVEPOINT`: se ela falhar, nada dela fica gravado (nem prestador, nem tomador).

Exemplo de erros de linha:

```json
"erros": [
    {
        "linha": 3,
        "mensagem": "data inválida: use uma data existente em YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS.",
        "original": "1003;A;2026-02-31;..."
    },
    {
        "linha": 6,
        "mensagem": "nota 1001/A do prestador 12345678000190 repetida no arquivo (já informada na linha 1).",
        "original": "1001;A;2026-08-20;12345678000190;..."
    }
]
```

## Falha geral

Se ocorrer um erro inesperado (ex.: falha de banco), a API responde HTTP `500`, toda a importação é desfeita e a tentativa fica registrada na tabela `importacoes` com status `ERRO`.

## Reimportação

A nota é identificada por **empresa + prestador + número + série**. Reenviar uma nota já importada **atualiza** o registro existente (valores, data, tomador, serviço e `importacao_id`), em vez de duplicá-la. A mesma nota repetida **dentro de um mesmo arquivo** é rejeitada como erro de linha.

## Histórico de importações

```http
GET /src/api/historico.php
GET /src/api/historico.php?id={id}
Authorization: Bearer <token>
```

Mostra apenas as importações da empresa do usuário. O `id` de uma importação de outra empresa responde `404`, como se não existisse.

### Lista

Parâmetros de query, todos opcionais:

| Parâmetro | Descrição |
|---|---|
| `status` | `PROCESSADA`, `PROCESSADA_COM_ERROS` ou `ERRO` |
| `arquivo` | trecho do nome do arquivo |
| `data_inicio` / `data_fim` | data da importação, `YYYY-MM-DD`, inclusivas |
| `pagina` | padrão `1` |
| `por_pagina` | padrão `20`, máximo `100` |

```bash
curl -H "Authorization: Bearer <token>" "http://localhost/ImportadorFiscalISS/src/api/historico.php?status=PROCESSADA&pagina=1"
```

```json
{
    "sucesso": true,
    "dados": {
        "importacoes": [
            {
                "id": 1,
                "arquivo": "notas_teste_iss.txt",
                "data_importacao": "2026-10-05 14:30:00",
                "status": "PROCESSADA",
                "total_linhas": 5,
                "total_processadas": 5,
                "total_erros": 0,
                "iss_total": 1537.54,
                "usuario": { "id": 1, "nome": "Fulano de Tal" }
            }
        ],
        "paginacao": {
            "pagina": 1,
            "por_pagina": 20,
            "total_registros": 1,
            "total_paginas": 1
        },
        "resumo": {
            "iss_total": 1537.54
        }
    }
}
```

`resumo.iss_total` soma **todas** as importações que atendem aos filtros, não só as da página.

### Detalhe

```bash
curl -H "Authorization: Bearer <token>" "http://localhost/ImportadorFiscalISS/src/api/historico.php?id=1"
```

Retorna os mesmos campos da lista (incluindo `usuario`, que é `null` em importações anteriores ao cadastro de usuários) e mais:

- `notas`: cada nota com `numero`, `serie`, `data_emissao`, `prestador` (`cnpj`, `razao_social`), `tomador` (`cpf_cnpj`, `razao_social`, ou `null`), `servico` (`codigo_lc116`, `descricao`), `municipio_prestacao`, `valor_servico`, `base_calculo`, `aliquota_iss`, `valor_iss` e `status`;
- `erros`: no mesmo formato do `importar.php` (`linha`, `mensagem`, `original`).

Como uma nota reimportada passa a pertencer à importação mais recente, o detalhe de uma importação antiga lista apenas as notas que ainda apontam para ela.

### Erros

| HTTP | Quando |
|---|---|
| `400` | parâmetro inválido (status desconhecido, data inexistente, `data_inicio` > `data_fim`, `por_pagina` fora de 1–100...) |
| `401` | token ausente, inválido ou expirado |
| `404` | `id` não encontrado (ou de outra empresa) |
| `405` | método diferente de `GET` |
| `500` | erro interno (só a mensagem genérica; o detalhe fica no log do PHP) |

## Regra de responsabilidade

### API

Responsável por:

- receber arquivo;
- validar requisição;
- chamar o importador;
- retornar HTTP/JSON.

### ImportadorTxt

Responsável por:

- separar linhas;
- validar campos;
- localizar serviço;
- rejeitar notas repetidas no mesmo arquivo;
- obter prestador/tomador;
- calcular ISS;
- inserir notas;
- registrar erros;
- finalizar a importação.

### HistoricoImportacoes

Responsável por consultar as importações, suas notas e erros, com filtros e paginação.

### ClassificadorServico

Responsável por buscar as regras do serviço no banco.

### CalculadoraIss

Responsável pelo cálculo matemático do ISS.

### Banco

Responsável por persistir os dados.

## Frontend

O `index.php` é apenas um exemplo de cliente da API.

Para um frontend Angular, React, Vue ou outro, a chamada será equivalente a:

```javascript
const formData = new FormData();
formData.append('arquivo', arquivo);

fetch('http://localhost/ImportadorFiscalISS/src/api/importar.php', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token }, // token devolvido por login.php
    body: formData
});
```

Não defina manualmente `Content-Type` ao usar `FormData`. O navegador cria o `multipart/form-data` com o boundary correto.

## CORS

O exemplo permite:

```http
Access-Control-Allow-Origin: *
```

Isso facilita os testes.

Em produção, substitua `*` pelo domínio real do frontend.

## Formato do TXT

```text
numero;serie;data_emissao;cnpj_prestador;razao_social_prestador;documento_tomador;razao_social_tomador;codigo_lc116;municipio_ibge;valor_servico
```

Exemplo:

```text
1001;A;2026-08-20;12345678000190;Empresa Exemplo Ltda;98765432000110;Cliente Exemplo Ltda;01.01;3550407;10000,00
```

- `data_emissao`: `YYYY-MM-DD` ou `YYYY-MM-DD HH:MM:SS`, precisa ser uma data existente. Sem hora, grava `00:00:00`.
- `valor_servico`: até 2 casas decimais; aceita `1000,50`, `1.000,50` e `1000.50`.

## Observação fiscal

O cadastro de `servicos` do SQL contém somente um registro demonstrativo para teste.

Antes de produção, as regras devem ser carregadas da base oficial correspondente ao município e à legislação aplicável. O código não deve assumir uma alíquota fixa para todos os serviços.


## Correção de URL

Se o projeto estiver em:

```text
C:\xampp\htdocs\ImportadorFiscalISS
```

e for acessado como:

```text
http://localhost/ImportadorFiscalISS/src/index.php
```

a API deve ser:

```text
http://localhost/ImportadorFiscalISS/src/api/importar.php
```

O frontend já vem configurado com esse caminho.

Primeiro teste:

```text
http://localhost/ImportadorFiscalISS/src/api/health.php
```

Se estiver funcionando, deverá retornar JSON.

A API também captura erros do PHP e tenta devolvê-los em JSON. Isso evita que erros comuns apareçam para o frontend como uma página HTML iniciada por `<!DOCTYPE`.
