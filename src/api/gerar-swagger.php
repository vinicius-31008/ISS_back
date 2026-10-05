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
        ],
        [
            'name' => 'Histórico',
            'description' => 'Consulta das importações já realizadas'
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
        ],
        '/src/api/historico.php' => [
            'get' => [
                'tags' => ['Histórico'],
                'summary' => 'Histórico de importações',
                'description' => 'Sem `id`: lista paginada das importações (mais recentes primeiro), com filtros opcionais. '
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
                    '404' => [
                        'description' => 'Importação não encontrada',
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
                    'iss_total' => ['type' => 'number', 'format' => 'float', 'example' => 1537.54]
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
