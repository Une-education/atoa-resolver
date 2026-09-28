<?php
require_once __DIR__ . '/../src/Engine/ConstraintGraph.php';
require_once __DIR__ . '/../src/Protocol/McpHandler.php';

use AtoA\Resolver\Protocol\McpHandler;

header('Content-Type: application/json; charset=utf-8');

$dbPath = __DIR__ . '/../data/resolver.sqlite';
if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Base de données indisponible']]);
    exit;
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rawInput = file_get_contents('php://input');
$req = json_decode($rawInput, true);

if (!$req || !isset($req['jsonrpc'])) {
    http_response_code(400);
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'JSON-RPC invalide']]);
    exit;
}

$handler = new McpHandler($pdo);
$response = $handler->handle($req);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
