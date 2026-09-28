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
