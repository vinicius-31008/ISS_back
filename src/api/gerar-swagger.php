<?php
declare(strict_types=1);

/*
 * Retorna a especificação OpenAPI usada pelo Swagger UI.
 *
 * Este arquivo não depende do analisador de anotações do swagger-php.
 * Isso mantém a documentação compatível com o PHP 7.4 do projeto,
 * enquanto o Swagger UI continua sendo usado normalmente em docs.html.
 */

header('Content-Type: application/json; charset=utf-8');

$openapi = [
    'openapi' => '3.0.0',
    'info' => [
        'title' => 'Importador Fiscal ISS — API',
        'version' => '1.1.0',
        'description' => 'API para upload de arquivos TXT de notas fiscais, classificação do serviço prestado (LC 116), cálculo do ISS devido e persistência no MySQL.'
    ],
    'servers' => [
        [
            'url' => 'http://localhost:8000',
            'description' => 'Ambiente local'
        ]
    ],
    'tags' => [
        [
            'name' => 'Sistema',
            'description' => 'Endpoints de status/infraestrutura da API'
        ],
        [
            'name' => 'Autenticação',
            'description' => 'Login/logout. As demais rotas exigem o token no cabeçalho Authorization: Bearer <token> e só enxergam os dados da empresa do usuário.'
        ],
        [
            'name' => 'Importação',
            'description' => 'Endpoints de importação e processamento fiscal do arquivo TXT'
        ],
        [
            'name' => 'Histórico',
            'description' => 'Consulta das importações já realizadas'
        ],
        [
            'name' => 'Empresas',
            'description' => 'CRUD de empresas (somente perfil ADMIN). DELETE desativa: importações e notas são mantidas.'
        ],
        [
            'name' => 'Usuários',
            'description' => 'CRUD de usuários (somente perfil ADMIN). DELETE desativa: o histórico guarda quem importou.'
        ]
    ],
    'paths' => [
        '/src/api/health.php' => [
            'get' => [
                'tags' => ['Sistema'],
                'summary' => 'Verifica se a API está online',
                'description' => 'Endpoint simples de healthcheck, sem dependência de banco de dados.',
                'responses' => [
                    '200' => [
                        'description' => 'API respondendo normalmente',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'sucesso' => ['type' => 'boolean', 'example' => true],
                                        'servico' => ['type' => 'string', 'example' => 'Importador Fiscal ISS API'],
                                        'status' => ['type' => 'string', 'example' => 'online'],
                                        'versao' => ['type' => 'string', 'example' => '1.1.0']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '405' => [
                        'description' => 'Método diferente de GET',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ]
                ]
            ]
        ],
        '/src/api/login.php' => [
            'post' => [
                'tags' => ['Autenticação'],
                'summary' => 'Login: troca e-mail e senha por um token',
                'description' => 'O token vale 8 horas e deve ser enviado nas demais rotas em Authorization: Bearer <token>. Aceita JSON ou form-data. '
                    . 'Primeiro acesso (banco sem nenhum usuário): entre com o administrador padrão admin@iss.local / admin123 (src/backend/UsuarioPadrao.php); a troca de senha é obrigatória antes de usar as demais rotas.',
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['email', 'senha'],
                                'properties' => [
                                    'email' => ['type' => 'string', 'example' => 'fulano@exemplo.com'],
                                    'senha' => ['type' => 'string', 'format' => 'password']
                                ]
                            ]
                        ]
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Login efetuado',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'sucesso' => ['type' => 'boolean', 'example' => true],
                                        'dados' => ['$ref' => '#/components/schemas/Sessao']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '400' => [
                        'description' => 'E-mail ou senha não informados',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ],
                    '401' => [
                        'description' => 'E-mail ou senha inválidos, ou usuário/empresa inativos',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ]
                ]
            ]
        ],
        '/src/api/trocar-senha.php' => [
            'post' => [
                'tags' => ['Autenticação'],
                'summary' => 'Troca a própria senha',
                'description' => 'Única rota liberada com senha provisória (trocar_senha = true). Mantém a sessão atual, que passa a ter acesso normal, e encerra as demais sessões do usuário.',
                'security' => [['bearerAuth' => []]],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['senha_atual', 'nova_senha'],
                                'properties' => [
                                    'senha_atual' => ['type' => 'string', 'format' => 'password'],
                                    'nova_senha' => ['type' => 'string', 'format' => 'password', 'minLength' => 8, 'maxLength' => 72]
                                ]
                            ]
                        ]
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Senha alterada',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'sucesso' => ['type' => 'boolean', 'example' => true],
                                        'mensagem' => ['type' => 'string', 'example' => 'Senha alterada. As demais sessões deste usuário foram encerradas.']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '400' => [
                        'description' => 'Campos ausentes, senha atual incorreta, nova senha fora de 8–72 caracteres ou igual à atual',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ],
                    '401' => ['$ref' => '#/components/responses/NaoAutenticado']
                ]
            ]
        ],
        '/src/api/logout.php' => [
            'post' => [
                'tags' => ['Autenticação'],
                'summary' => 'Logout: invalida o token enviado',
                'security' => [['bearerAuth' => []]],
                'responses' => [
                    '200' => [
                        'description' => 'Sessão encerrada (também quando o token já era inválido)',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'sucesso' => ['type' => 'boolean', 'example' => true],
                                        'mensagem' => ['type' => 'string', 'example' => 'Sessão encerrada.']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ],
        '/src/api/importar.php' => [
            'post' => [
                'tags' => ['Importação'],
                'summary' => 'Importa um arquivo TXT de notas fiscais e calcula o ISS',
                'description' => 'Recebe um arquivo TXT (multipart/form-data), valida e processa linha a linha: classifica o serviço pelo código LC116, calcula o ISS devido e grava a importação, as notas e eventuais erros de linha no MySQL. '
                    . 'Tudo fica vinculado à empresa do usuário autenticado: prestadores, tomadores e notas de uma empresa não se misturam com os de outra.',
                'security' => [['bearerAuth' => []]],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['arquivo'],
                                'properties' => [
                                    'arquivo' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                        'description' => 'Arquivo .txt com uma nota fiscal por linha, campos separados por ";".'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Arquivo processado',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'sucesso' => ['type' => 'boolean', 'example' => true],
                                        'mensagem' => ['type' => 'string', 'example' => 'Arquivo processado com sucesso.'],
                                        'dados' => ['$ref' => '#/components/schemas/ImportacaoResultado']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '400' => [
                        'description' => 'Requisição inválida',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ],
                    '401' => ['$ref' => '#/components/responses/NaoAutenticado'],
                    '405' => [
                        'description' => 'Método diferente de POST',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ],
                    '500' => [
                        'description' => 'Erro interno',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ]
                ]
            ]
        ],
        '/src/api/historico.php' => [
            'get' => [
                'tags' => ['Histórico'],
                'summary' => 'Histórico de importações',
                'security' => [['bearerAuth' => []]],
                'description' => 'Mostra apenas as importações da empresa do usuário autenticado; o `id` de uma importação de outra empresa responde 404. '
                    . 'Sem `id`: lista paginada das importações (mais recentes primeiro), com filtros opcionais. '
                    . 'Com `id`: detalhe de uma importação, incluindo as notas e os erros de linha. '
                    . 'Uma nota reimportada passa a pertencer à importação mais recente, então o detalhe de uma importação antiga lista apenas as notas que ainda apontam para ela.',
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'query',
                        'required' => false,
                        'description' => 'ID da importação. Quando informado, retorna o detalhe e ignora os demais parâmetros.',
                        'schema' => ['type' => 'integer', 'minimum' => 1]
                    ],
                    [
                        'name' => 'status',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string', 'enum' => ['PROCESSADA', 'PROCESSADA_COM_ERROS', 'ERRO']]
                    ],
                    [
                        'name' => 'arquivo',
                        'in' => 'query',
                        'required' => false,
                        'description' => 'Trecho do nome do arquivo.',
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'data_inicio',
                        'in' => 'query',
                        'required' => false,
                        'description' => 'Data da importação, inclusiva (YYYY-MM-DD).',
                        'schema' => ['type' => 'string', 'format' => 'date']
                    ],
                    [
                        'name' => 'data_fim',
                        'in' => 'query',
                        'required' => false,
                        'description' => 'Data da importação, inclusiva (YYYY-MM-DD).',
                        'schema' => ['type' => 'string', 'format' => 'date']
                    ],
                    [
                        'name' => 'pagina',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]
                    ],
                    [
                        'name' => 'por_pagina',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Lista paginada (sem `id`) ou detalhe da importação (com `id`)',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'sucesso' => ['type' => 'boolean', 'example' => true],
                                        'dados' => [
                                            'oneOf' => [
                                                ['$ref' => '#/components/schemas/HistoricoLista'],
                                                ['$ref' => '#/components/schemas/HistoricoDetalhe']
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '400' => [
                        'description' => 'Parâmetro inválido',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ],
                    '401' => ['$ref' => '#/components/responses/NaoAutenticado'],
                    '404' => [
                        'description' => 'Importação não encontrada (ou de outra empresa)',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ],
                    '405' => [
                        'description' => 'Método diferente de GET',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ],
                    '500' => [
                        'description' => 'Erro interno',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ] + caminhosCrud(
        '/src/api/empresas.php',
        'Empresas',
        'empresa',
        'Empresa',
        'EmpresaEntrada',
        [
            ['name' => 'busca', 'in' => 'query', 'required' => false, 'description' => 'Trecho da razão social ou do CNPJ.', 'schema' => ['type' => 'string']],
            ['name' => 'ativo', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']]
        ],
        'O admin não pode desativar a própria empresa.'
    ) + caminhosCrud(
        '/src/api/usuarios.php',
        'Usuários',
        'usuário',
        'Usuario',
        'UsuarioEntrada',
        [
            ['name' => 'busca', 'in' => 'query', 'required' => false, 'description' => 'Trecho do nome ou do e-mail.', 'schema' => ['type' => 'string']],
            ['name' => 'empresa_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'perfil', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['ADMIN', 'USUARIO']]],
            ['name' => 'ativo', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']]
        ],
        'Trocar a senha ou desativar encerra as sessões abertas do usuário. O admin não pode desativar a si mesmo nem remover o próprio perfil ADMIN. A senha nunca é devolvida.'
    ),
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'Token devolvido por /src/api/login.php.'
            ]
        ],
        'responses' => [
            'NaoAutenticado' => [
                'description' => 'Token ausente, inválido ou expirado (com senha provisória, as rotas respondem 403 até a troca de senha)',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/RespostaErro']
                    ]
                ]
            ]
        ],
        'schemas' => [
            'Sessao' => [
                'type' => 'object',
                'properties' => [
                    'token' => ['type' => 'string', 'example' => '3f9a...c21e'],
                    'expira_em' => ['type' => 'string', 'example' => '2026-10-14 22:30:00'],
                    'usuario' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'nome' => ['type' => 'string', 'example' => 'Fulano de Tal'],
                            'email' => ['type' => 'string', 'example' => 'fulano@exemplo.com'],
                            'perfil' => ['type' => 'string', 'enum' => ['ADMIN', 'USUARIO'], 'example' => 'ADMIN'],
                            'trocar_senha' => [
                                'type' => 'boolean',
                                'example' => false,
                                'description' => 'true = senha provisória (ex.: administrador padrão): o token só serve para /src/api/trocar-senha.php; as demais rotas respondem 403.'
                            ]
                        ]
                    ],
                    'empresa' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'cnpj' => ['type' => 'string', 'example' => '12345678000190'],
                            'razao_social' => ['type' => 'string', 'example' => 'Empresa Exemplo Ltda']
                        ]
                    ]
                ]
            ],
            'Empresa' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'cnpj' => ['type' => 'string', 'example' => '12345678000190'],
                    'razao_social' => ['type' => 'string', 'example' => 'Empresa Exemplo Ltda'],
                    'ativo' => ['type' => 'boolean', 'example' => true],
                    'usuarios_ativos' => ['type' => 'integer', 'example' => 3],
                    'criado_em' => ['type' => 'string', 'example' => '2026-10-14 09:00:00']
                ]
            ],
            'EmpresaEntrada' => [
                'type' => 'object',
                'description' => 'POST: cnpj e razao_social obrigatórios. PUT: envie só o que vai mudar. Campos desconhecidos são rejeitados.',
                'properties' => [
                    'cnpj' => ['type' => 'string', 'example' => '12.345.678/0001-90', 'description' => 'Com ou sem máscara; gravado só com os 14 dígitos.'],
                    'razao_social' => ['type' => 'string', 'maxLength' => 200, 'example' => 'Empresa Exemplo Ltda'],
                    'ativo' => ['type' => 'boolean', 'default' => true, 'description' => 'true reativa uma empresa desativada.']
                ]
            ],
            'Usuario' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 2],
                    'nome' => ['type' => 'string', 'example' => 'Fulano de Tal'],
                    'email' => ['type' => 'string', 'example' => 'fulano@exemplo.com'],
                    'perfil' => ['type' => 'string', 'enum' => ['ADMIN', 'USUARIO'], 'example' => 'USUARIO'],
                    'ativo' => ['type' => 'boolean', 'example' => true],
                    'empresa' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'cnpj' => ['type' => 'string', 'example' => '12345678000190'],
                            'razao_social' => ['type' => 'string', 'example' => 'Empresa Exemplo Ltda']
                        ]
                    ],
                    'criado_em' => ['type' => 'string', 'example' => '2026-10-14 09:00:00']
                ]
            ],
            'UsuarioEntrada' => [
                'type' => 'object',
                'description' => 'POST: empresa_id, nome, email e senha obrigatórios. PUT: envie só o que vai mudar. Campos desconhecidos são rejeitados.',
                'properties' => [
                    'empresa_id' => ['type' => 'integer', 'example' => 1, 'description' => 'Empresa existente e ativa.'],
                    'nome' => ['type' => 'string', 'maxLength' => 150, 'example' => 'Fulano de Tal'],
                    'email' => ['type' => 'string', 'maxLength' => 255, 'example' => 'fulano@exemplo.com', 'description' => 'Único no sistema.'],
                    'senha' => ['type' => 'string', 'format' => 'password', 'minLength' => 8, 'maxLength' => 72],
                    'perfil' => ['type' => 'string', 'enum' => ['ADMIN', 'USUARIO'], 'default' => 'USUARIO'],
                    'ativo' => ['type' => 'boolean', 'default' => true, 'description' => 'true reativa um usuário desativado.']
                ]
            ],
            'ErroLinha' => [
                'type' => 'object',
                'properties' => [
                    'linha' => [
                        'type' => 'integer',
                        'example' => 3,
                        'description' => 'Número da linha no arquivo TXT (1-based)'
                    ],
                    'mensagem' => [
                        'type' => 'string',
                        'example' => 'serviço 99.99 não cadastrado.'
                    ],
                    'original' => [
                        'type' => 'string',
                        'example' => '1003;A;2026-08-20;...;99.99;3550407;10000,00'
                    ]
                ]
            ],
            'ImportacaoResultado' => [
                'type' => 'object',
                'properties' => [
                    'importacao_id' => ['type' => 'integer', 'example' => 1],
                    'arquivo' => ['type' => 'string', 'example' => 'notas_teste_iss.txt'],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['PROCESSADA', 'PROCESSADA_COM_ERROS'],
                        'example' => 'PROCESSADA'
                    ],
                    'total_linhas' => ['type' => 'integer', 'example' => 5],
                    'total_processadas' => ['type' => 'integer', 'example' => 5],
                    'total_erros' => ['type' => 'integer', 'example' => 0],
                    'iss_total' => ['type' => 'number', 'format' => 'float', 'example' => 1537.54],
                    'erros' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ErroLinha']
                    ]
                ]
            ],
            'ImportacaoResumo' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'arquivo' => ['type' => 'string', 'example' => 'notas_teste_iss.txt'],
                    'data_importacao' => ['type' => 'string', 'example' => '2026-10-05 14:30:00'],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['PROCESSADA', 'PROCESSADA_COM_ERROS', 'ERRO'],
                        'example' => 'PROCESSADA'
                    ],
                    'total_linhas' => ['type' => 'integer', 'example' => 5],
                    'total_processadas' => ['type' => 'integer', 'example' => 5],
                    'total_erros' => ['type' => 'integer', 'example' => 0],
                    'iss_total' => ['type' => 'number', 'format' => 'float', 'example' => 1537.54],
                    'usuario' => [
                        'type' => 'object',
                        'nullable' => true,
                        'description' => 'Quem fez a importação. Null nas importações anteriores ao cadastro de usuários.',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'nome' => ['type' => 'string', 'example' => 'Fulano de Tal']
                        ]
                    ]
                ]
            ],
            'HistoricoLista' => [
                'type' => 'object',
                'properties' => [
                    'importacoes' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ImportacaoResumo']
                    ],
                    'paginacao' => [
                        'type' => 'object',
                        'properties' => [
                            'pagina' => ['type' => 'integer', 'example' => 1],
                            'por_pagina' => ['type' => 'integer', 'example' => 20],
                            'total_registros' => ['type' => 'integer', 'example' => 1],
                            'total_paginas' => ['type' => 'integer', 'example' => 1]
                        ]
                    ],
                    'resumo' => [
                        'type' => 'object',
                        'description' => 'Totais de todas as importações que atendem aos filtros (não só da página).',
                        'properties' => [
                            'iss_total' => ['type' => 'number', 'format' => 'float', 'example' => 1537.54]
                        ]
                    ]
                ]
            ],
            'NotaHistorico' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'numero' => ['type' => 'string', 'example' => '1001'],
                    'serie' => ['type' => 'string', 'example' => 'A'],
                    'data_emissao' => ['type' => 'string', 'example' => '2026-08-20 00:00:00'],
                    'prestador' => [
                        'type' => 'object',
                        'properties' => [
                            'cnpj' => ['type' => 'string', 'example' => '12345678000190'],
                            'razao_social' => ['type' => 'string', 'example' => 'Empresa Exemplo Ltda']
                        ]
                    ],
                    'tomador' => [
                        'type' => 'object',
                        'nullable' => true,
                        'properties' => [
                            'cpf_cnpj' => ['type' => 'string', 'example' => '98765432000110'],
                            'razao_social' => ['type' => 'string', 'example' => 'Cliente Exemplo Ltda']
                        ]
                    ],
                    'servico' => [
                        'type' => 'object',
                        'properties' => [
                            'codigo_lc116' => ['type' => 'string', 'example' => '01.01'],
                            'descricao' => ['type' => 'string', 'example' => 'Análise e desenvolvimento de sistemas']
                        ]
                    ],
                    'municipio_prestacao' => ['type' => 'string', 'nullable' => true, 'example' => '3550407'],
                    'valor_servico' => ['type' => 'number', 'format' => 'float', 'example' => 10000.00],
                    'base_calculo' => ['type' => 'number', 'format' => 'float', 'example' => 10000.00],
                    'aliquota_iss' => ['type' => 'number', 'format' => 'float', 'example' => 5.0],
                    'valor_iss' => ['type' => 'number', 'format' => 'float', 'example' => 500.00],
                    'status' => ['type' => 'string', 'example' => 'CALCULADA']
                ]
            ],
            'HistoricoDetalhe' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/ImportacaoResumo'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'notas' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/NotaHistorico']
                            ],
                            'erros' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/ErroLinha']
                            ]
                        ]
                    ]
                ]
            ],
            'RespostaErro' => [
                'type' => 'object',
                'properties' => [
                    'sucesso' => ['type' => 'boolean', 'example' => false],
                    'mensagem' => ['type' => 'string', 'example' => 'Apenas arquivos .txt são aceitos.'],
                    'erro' => ['type' => 'string', 'example' => 'mensagem técnica da exceção']
                ]
            ]
        ]
    ]
];

echo json_encode($openapi, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

/**
 * Especificação de um CRUD (empresas.php, usuarios.php): mesmas rotas, respostas e
 * regras, mudando só o recurso. ?id= identifica o registro em GET (detalhe), PUT e DELETE.
 */
function caminhosCrud(string $caminho, string $tag, string $nome, string $schema, string $schemaEntrada, array $filtros, string $regras): array
{
    $erro = function (string $descricao): array {
        return [
            'description' => $descricao,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/RespostaErro']]]
        ];
    };
    $sucesso = function (string $descricao, ?array $dados = null): array {
        $propriedades = [
            'sucesso' => ['type' => 'boolean', 'example' => true],
            'mensagem' => ['type' => 'string']
        ];
        if ($dados !== null) {
            $propriedades['dados'] = $dados;
        }
        return [
            'description' => $descricao,
            'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $propriedades]]]
        ];
    };

    $registro = ['$ref' => "#/components/schemas/$schema"];
    $corpo = [
        'required' => true,
        'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/$schemaEntrada"]]]
    ];
    $id = ['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]];
    $idOpcional = ['required' => false, 'description' => 'Quando informado, retorna o detalhe e ignora os filtros.'] + $id;
    $paginacao = [
        ['name' => 'pagina', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
        ['name' => 'por_pagina', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]]
    ];
    $comuns = [
        '401' => ['$ref' => '#/components/responses/NaoAutenticado'],
        '403' => $erro('Usuário sem perfil ADMIN'),
        '500' => $erro('Erro interno')
    ];
    $lista = $tag === 'Empresas' ? 'empresas' : 'usuarios';

    return [$caminho => [
        'get' => [
            'tags' => [$tag],
            'summary' => "Lista ($lista paginados) ou detalha (?id=) $nome",
            'security' => [['bearerAuth' => []]],
            'parameters' => array_merge([$idOpcional], $filtros, $paginacao),
            'responses' => [
                '200' => $sucesso('Lista (sem id) ou registro (com id)', ['oneOf' => [
                    ['type' => 'object', 'properties' => [
                        $lista => ['type' => 'array', 'items' => $registro],
                        'paginacao' => ['type' => 'object', 'properties' => [
                            'pagina' => ['type' => 'integer'],
                            'por_pagina' => ['type' => 'integer'],
                            'total_registros' => ['type' => 'integer'],
                            'total_paginas' => ['type' => 'integer']
                        ]]
                    ]],
                    $registro
                ]]),
                '400' => $erro('Parâmetro inválido'),
                '404' => $erro(ucfirst($nome) . ' não encontrado(a)')
            ] + $comuns
        ],
        'post' => [
            'tags' => [$tag],
            'summary' => "Cria $nome",
            'description' => $regras,
            'security' => [['bearerAuth' => []]],
            'requestBody' => $corpo,
            'responses' => [
                '201' => $sucesso('Criado', $registro),
                '400' => $erro('Campo obrigatório ausente, inválido ou desconhecido'),
                '409' => $erro('Duplicidade (CNPJ ou e-mail já cadastrado)')
            ] + $comuns
        ],
        'put' => [
            'tags' => [$tag],
            'summary' => "Atualiza $nome (só os campos enviados)",
            'description' => $regras,
            'security' => [['bearerAuth' => []]],
            'parameters' => [$id],
            'requestBody' => $corpo,
            'responses' => [
                '200' => $sucesso('Atualizado', $registro),
                '400' => $erro('Campo inválido, nenhum campo enviado ou operação sobre o próprio acesso'),
                '404' => $erro(ucfirst($nome) . ' não encontrado(a)'),
                '409' => $erro('Duplicidade (CNPJ ou e-mail já cadastrado)')
            ] + $comuns
        ],
        'delete' => [
            'tags' => [$tag],
            'summary' => "Desativa $nome (exclusão lógica; reative com PUT {\"ativo\": true})",
            'description' => $regras,
            'security' => [['bearerAuth' => []]],
            'parameters' => [$id],
            'responses' => [
                '200' => $sucesso('Desativado'),
                '400' => $erro('id ausente ou operação sobre o próprio acesso'),
                '404' => $erro(ucfirst($nome) . ' não encontrado(a)')
            ] + $comuns
        ]
    ]];
}
