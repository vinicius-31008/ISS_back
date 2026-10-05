# Swagger corrigido

A documentação Swagger deste projeto foi ajustada para funcionar com o PHP 7.4.

## Como iniciar

Na pasta `ISS_back-main`:

```bash
php -S localhost:8000
```

## Endereços

- Swagger UI: http://localhost:8000/src/api/docs.html
- Especificação OpenAPI: http://localhost:8000/src/api/gerar-swagger.php
- Healthcheck: http://localhost:8000/src/api/health.php
- Importação: POST http://localhost:8000/src/api/importar.php
- Histórico: GET http://localhost:8000/src/api/historico.php (lista) e `?id={id}` (detalhe)

## O que foi corrigido

O Swagger UI estava recebendo apenas `{ "openapi": "3.0.0" }`, porque o analisador de anotações não conseguia montar corretamente os endpoints no PHP 7.4.

`src/api/gerar-swagger.php` agora retorna diretamente uma especificação OpenAPI 3.0 válida, incluindo:

- `info`
- `servers`
- `tags`
- `paths`
- `components.schemas`
- GET `/src/api/health.php`
- POST `/src/api/importar.php`
- GET `/src/api/historico.php`

O restante da lógica da API não foi alterado.

## Ao criar ou alterar endpoints

O Swagger exibido vem **apenas** do array em `src/api/gerar-swagger.php`. As anotações `@OA` nos arquivos PHP não são lidas. Sempre que um endpoint mudar, atualize esse array.
