<?php
require_once __DIR__ . '/../src/Engine/ConstraintGraph.php';

use AtoA\Resolver\Engine\ConstraintGraph;

$dbPath = __DIR__ . '/../data/resolver.sqlite';
$dbStatus = file_exists($dbPath) ? 'OPERATIONAL' : 'MISSING_DB';
$pdo = null;

if ($dbStatus === 'OPERATIONAL') {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        $dbStatus = 'DB_ERROR';
    }
}

// Router API REST : POST /v1/resolve ou POST /
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && ($uri === '/' || $uri === '/v1/resolve')) {
    header('Content-Type: application/json; charset=utf-8');

    if ($dbStatus !== 'OPERATIONAL' || !$pdo) {
        http_response_code(500);
        echo json_encode(['error' => 'Database unavailable', 'status' => $dbStatus]);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input || !isset($input['hs_code'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Payload invalide. Requis: hs_code (ex: "8542.31"), origin (ex: "CN"), destination (ex: "FR")',
            'received' => $input
        ]);
        exit;
    }

    $graph = new ConstraintGraph($pdo);
    $result = $graph->resolve($input);

    // Journalisation de l'audit
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO resolution_logs (session_hash, query_json, status, causal_chain_json, execution_time_ms)
            VALUES (?, ?, ?, ?, ?)
        ");
        $logStmt->execute([
            bin2hex(random_bytes(8)),
            $rawInput,
            $result['status'],
            json_encode($result['conflicts'], JSON_UNESCAPED_UNICODE),
            $result['computation_time_ms']
        ]);
    } catch (Exception $e) {
        // Log silencieux pour ne pas bloquer la réponse de calcul
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Rendu GET : Dashboard HTML
$rulesCount = 0;
$resolutionsCount = 0;
if ($pdo) {
    try {
        $rulesCount = (int)$pdo->query("SELECT COUNT(*) FROM rules")->fetchColumn();
        $resolutionsCount = (int)$pdo->query("SELECT COUNT(*) FROM resolution_logs")->fetchColumn();
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AtoA Resolver | Compute &amp; Logic Layer</title>
    <style>
        :root {
            --bg-color: #0d1117;
            --panel-bg: #161b22;
            --border-color: #30363d;
            --text-main: #c9d1d9;
            --text-muted: #8b949e;
            --accent: #58a6ff;
            --accent-green: #238636;
            --font-mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
            --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            font-family: var(--font-sans);
            line-height: 1.6;
            padding: 2.5rem 1.5rem;
            display: flex;
            justify-content: center;
        }

        .container { max-width: 900px; width: 100%; }

        header {
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1.5rem;
        }

        .badge-bar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .badge {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .badge-status {
            background-color: rgba(35, 134, 54, 0.2);
            color: #3fb950;
            border: 1px solid rgba(56, 139, 253, 0.15);
        }

        .badge-node {
            background-color: rgba(88, 166, 255, 0.1);
            color: var(--accent);
            border: 1px solid rgba(88, 166, 255, 0.3);
        }

        h1 {
            font-size: 1.85rem;
            font-weight: 700;
            color: #f0f6fc;
            letter-spacing: -0.02em;
        }

        .tagline {
            color: var(--text-muted);
            font-size: 1rem;
            margin-top: 0.25rem;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 700px) {
            .grid { grid-template-columns: 1fr; }
        }

        .card {
            background-color: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 1.25rem;
        }

        .card h2 {
            font-size: 1.05rem;
            color: #f0f6fc;
            margin-bottom: 0.75rem;
        }

        .card p, .card li {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        ul { list-style: none; margin-top: 0.5rem; }

        ul li {
            padding: 0.35rem 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.05);
        }

        ul li:last-child { border-bottom: none; }

        code {
            font-family: var(--font-mono);
            font-size: 0.85rem;
            background-color: rgba(110, 118, 129, 0.2);
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            color: #58a6ff;
        }

        .full-card { grid-column: 1 / -1; }

        pre {
            background-color: #0b0e14;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 1rem;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            overflow-x: auto;
            color: #e6edf3;
            margin-top: 0.75rem;
        }

        footer {
            margin-top: 2rem;
            border-top: 1px solid var(--border-color);
            padding-top: 1rem;
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="badge-bar">
                <span class="badge badge-node">Compute &amp; Logic Layer</span>
                <span class="badge badge-status"><?= htmlspecialchars($dbStatus) ?></span>
            </div>
            <h1>AtoA Resolver (resolver.une.education)</h1>
            <p class="tagline">Moteur d'inférence déterministe et graphe de contraintes pour agents autonomes.</p>
        </header>

        <div class="grid">
            <div class="card">
                <h2>01. Positionnement Système</h2>
                <p>Nœud d'arbitrage logique positionné au-dessus des couches de données froides :</p>
                <ul>
                    <li><code>legal.une.education</code> (Corpus juridiques, Sino, OFAC)</li>
                    <li><code>customs.une.education</code> (Nomenclatures TARIC &amp; SH)</li>
                    <li><strong>Garantie :</strong> Élimination des hallucinations par CSP formel.</li>
                </ul>
            </div>

            <div class="card">
                <h2>02. État du Graphe Local</h2>
                <ul>
                    <li><strong>Règles compilées :</strong> <?= $rulesCount ?> contrainte(s)</li>
                    <li><strong>Arbitrages enregistrés :</strong> <?= $resolutionsCount ?> résolution(s)</li>
                    <li><strong>Endpoint REST :</strong> <code>POST /v1/resolve</code></li>
                    <li><strong>Protocole Agent :</strong> JSON-RPC 2.0 (MCP)</li>
                </ul>
            </div>

            <div class="card full-card">
                <h2>03. Exemple d'Interrogation REST</h2>
                <p>Envoyez une requête POST directe avec le code SH et les faits connus :</p>
                <pre>curl -X POST https://resolver.une.education/v1/resolve \
  -H "Content-Type: application/json" \
  -d '{
    "origin": "CN",
    "destination": "FR",
    "hs_code": "8542.31",
    "facts": ["ENTITY_SANCTIONED", "LIC_EXPORT_PERMIT_APPROVED"]
  }'</pre>
            </div>
        </div>

        <footer>
            <span>une.education &bull; AtoA Project</span>
            <span>Node: resolver.une.education</span>
        </footer>
    </div>
</body>
</html>
