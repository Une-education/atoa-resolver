<?php
require_once __DIR__ . '/../src/Engine/ConstraintGraph.php';

use AtoA\Resolver\Engine\ConstraintGraph;

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/resolver.sqlite');
$graph = new ConstraintGraph($pdo);

echo "=== SCÉNARIO 1 : Import direct sans document ===\n";
$res1 = $graph->resolve([
    'origin' => 'CN',
    'destination' => 'FR',
    'hs_code' => '8542.31',
    'facts' => []
]);
echo json_encode($res1, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== SCÉNARIO 2 : Import avec entité sanctionnée déclarée ===\n";
$res2 = $graph->resolve([
    'origin' => 'CN',
    'destination' => 'FR',
    'hs_code' => '8542.31',
    'facts' => ['ENTITY_SANCTIONED', 'LIC_EXPORT_PERMIT_APPROVED']
]);
echo json_encode($res2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
