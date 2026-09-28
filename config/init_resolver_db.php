<?php
$dbPath = __DIR__ . '/../data/resolver.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Définition des règles élémentaires (Prédicats de base)
$pdo->exec("
CREATE TABLE IF NOT EXISTS rules (
    rule_id TEXT PRIMARY KEY,
    domain TEXT NOT NULL,
    hs_pattern TEXT,
    origin TEXT,
    destination TEXT,
    description TEXT,
    severity TEXT CHECK(severity IN ('INFO', 'WARN', 'BLOCK', 'REQUIREMENT')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 2. Dépendances & Obligations (Arêtes du graphe)
CREATE TABLE IF NOT EXISTS rule_requirements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rule_id TEXT NOT NULL,
    requires_node TEXT NOT NULL,
    condition_expression TEXT,
    FOREIGN KEY(rule_id) REFERENCES rules(rule_id)
);

-- 3. Antagonismes & Incompatibilités (Conflits bloquants)
CREATE TABLE IF NOT EXISTS antagonisms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    node_a TEXT NOT NULL,
    node_b TEXT NOT NULL,
    conflict_type TEXT NOT NULL,
    reason TEXT NOT NULL
);

-- 4. Journal d'audit et résolution pour les agents
CREATE TABLE IF NOT EXISTS resolution_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_hash TEXT NOT NULL,
    query_json TEXT NOT NULL,
    status TEXT NOT NULL,
    causal_chain_json TEXT,
    execution_time_ms REAL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_rules_routing ON rules(origin, destination, hs_pattern);
");

echo "Base resolver.sqlite initialisée avec succès.\n";
