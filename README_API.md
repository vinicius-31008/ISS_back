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

## Endpoint

```http
POST /api/importar.php
Content-Type: multipart/form-data
```

Campo obrigatório:

```text
arquivo = arquivo.txt
```

### Exemplo com cURL

```bash
curl -X POST \
  -F "arquivo=@notas_teste_iss.txt" \
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
- obter prestador/tomador;
- calcular ISS;
- inserir notas;
- registrar erros;
- finalizar a importação.

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
