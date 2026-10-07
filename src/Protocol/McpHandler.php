<?php
namespace AtoA\Resolver\Protocol;

use AtoA\Resolver\Engine\ConstraintGraph;
use PDO;

class McpHandler {
    private ConstraintGraph $graph;

    public function __construct(PDO $pdo) {
        $this->graph = new ConstraintGraph($pdo);
    }

    public function handle(array $req): array {
        $id = $req['id'] ?? null;
        $method = $req['method'] ?? '';

        switch ($method) {
            case 'initialize':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => [
                            'tools' => new \stdClass(),
                            'resources' => new \stdClass(),
                            'prompts' => new \stdClass()
                        ],
                        'serverInfo' => [
                            'name' => 'atoa-resolver',
                            'version' => '1.0.0'
                        ]
                    ]
                ];

            case 'tools/list':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'tools' => [
                            [
                                'name' => 'trade_arbitration_pipeline',
                                'description' => 'Méta-orchestrateur AtoA : arbitre un litige ou une transaction transfrontalière en unifiant la liquidation douanière UE (TARIC), le criblage des sanctions internationales (OFAC/UE), les délais de forclusion bilatéraux (Chine Art. 621 / CVIM) et le droit français.',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'origin' => [
                                            'type' => 'string',
                                            'description' => 'Code pays ISO 2 d’origine (ex: CN, US)'
                                        ],
                                        'destination' => [
                                            'type' => 'string',
                                            'description' => 'Code pays ISO 2 de destination (ex: FR, DE)'
                                        ],
                                        'hs_code' => [
                                            'type' => 'string',
                                            'description' => 'Code SH / TARIC de la marchandise (ex: 640411, 854231)'
                                        ],
                                        'customs_value_eur' => [
                                            'type' => 'number',
                                            'description' => 'Valeur CAF / CIF de la transaction en EUR'
                                        ],
                                        'delivery_date' => [
                                            'type' => 'string',
                                            'description' => 'Date de livraison de la cargaison (YYYY-MM-DD)'
                                        ],
                                        'defect_discovered_date' => [
                                            'type' => 'string',
                                            'description' => 'Date de découverte du vice ou défaut (YYYY-MM-DD)'
                                        ],
                                        'entities' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                            'description' => 'Noms légaux des parties (exportateur, importateur, transporteur) pour criblage sanctions'
                                        ]
                                    ],
                                    'required' => ['origin', 'destination', 'hs_code', 'customs_value_eur']
                                ]
                            ],
                            [
                                'name' => 'resolve_compliance',
                                'description' => 'Arbitre de manière formelle et déterministe la conformité réglementaire (douane, sanctions OFAC/UE, export control) sur un flux transfrontalier via un graphe de contraintes (zéro hallucination).',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'origin' => [
                                            'type' => 'string',
                                            'description' => 'Code pays ISO 2 d’origine (ex: CN, US, FR)'
                                        ],
                                        'destination' => [
                                            'type' => 'string',
                                            'description' => 'Code pays ISO 2 de destination (ex: FR, DE, US)'
                                        ],
                                        'hs_code' => [
                                            'type' => 'string',
                                            'description' => 'Code SH / TARIC du produit (ex: 8542.31, 2804.80)'
                                        ],
                                        'entities' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                            'description' => 'Noms des entités impliquées pour criblage sanctions'
                                        ],
                                        'facts' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                            'description' => 'Faits ou documents déjà réunis'
                                        ]
                                    ],
                                    'required' => ['origin', 'destination', 'hs_code']
                                ]
                            ]
                        ]
                    ]
                ];

            case 'resources/list':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'resources' => []
                    ]
                ];

            case 'prompts/list':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'prompts' => []
                    ]
                ];

            case 'tools/call':
                $params = $req['params'] ?? [];
                $name = $params['name'] ?? '';
                $args = $params['arguments'] ?? [];

                                if ($name === 'trade_arbitration_pipeline') {
                    $resolution = $this->graph->arbitrateTrade($args);
                    return [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => json_encode($resolution, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                ]
                            ]
                        ]
                    ];
                }

                if ($name === 'resolve_compliance') {
                    $resolution = $this->graph->resolve($args);
                    return [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => json_encode($resolution, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                ]
                            ]
                        ]
                    ];
                }

                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32601, 'message' => "Outil inconnu : $name"]
                ];

            default:
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32601, 'message' => "Méthode non implémentée : $method"]
                ];
        }
    }
}
