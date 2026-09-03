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
            'name' => 'Importação',
            'description' => 'Endpoints de importação e processamento fiscal do arquivo TXT'
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
        '/src/api/importar.php' => [
            'post' => [
                'tags' => ['Importação'],
                'summary' => 'Importa um arquivo TXT de notas fiscais e calcula o ISS',
                'description' => 'Recebe um arquivo TXT (multipart/form-data), valida e processa linha a linha: classifica o serviço pelo código LC116, calcula o ISS devido e grava a importação, as notas e eventuais erros de linha no MySQL.',
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
        ]
    ],
    'components' => [
        'schemas' => [
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
