<?php
declare(strict_types=1);

$t_start = microtime(true);

require_once __DIR__ . '/../src/Engine/ConstraintGraph.php';
require_once __DIR__ . '/../src/Protocol/McpHandler.php';

use AtoA\Resolver\Protocol\McpHandler;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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

// Traçabilité télémétrique unifiée
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        ip TEXT,
        method TEXT,
        user_agent TEXT,
        payload TEXT,
        exec_time_ms REAL
    );");
    $execMs = round((microtime(true) - $t_start) * 1000, 2);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $stmt = $pdo->prepare("INSERT INTO mcp_logs (ip, method, user_agent, payload, exec_time_ms) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$ip, $req['method'] ?? 'unknown', $ua, substr($rawInput, 0, 500), $execMs]);
} catch (\Throwable $e) {}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
