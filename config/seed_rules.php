<?php
$dbPath = __DIR__ . '/../data/resolver.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Vider les tables
$pdo->exec("DELETE FROM rule_requirements; DELETE FROM antagonisms; DELETE FROM rules;");

$stmtRule = $pdo->prepare("
    INSERT INTO rules (rule_id, domain, hs_pattern, origin, destination, description, severity)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

// 1. UE - Règlement 2021/821 (Biens à double usage / Microélectronique)
$stmtRule->execute([
    'RULE_EU_DUAL_USE_8542',
    'export_control',
    '8542%',
    'CN',
    'FR',
    'Contrôle double usage microprocesseurs et semi-conducteurs (Règlement UE 2021/821)',
    'BLOCK'
]);

// 2. CHINE - Réglementation MOFCOM / Contrôle export Gallium & Germanium (HS 2804/8112)
$stmtRule->execute([
    'RULE_CN_CRITICAL_METALS_MOFCOM',
    'export_control',
    '280480%',
    'CN',
    '*',
    'Contrôle export chinois sur les minéraux et métaux critiques (MOFCOM / Sécurité Nationale)',
    'BLOCK'
]);

// 3. US - EAR Dual-Use et composants sensibles
$stmtRule->execute([
    'RULE_US_EAR_RESTRICTION',
    'export_control',
    '8542%',
    'US',
    'CN',
    'Réglementation US Export Administration Regulations (EAR) sur semi-conducteurs avancés',
    'BLOCK'
]);

// Dépendances et obligations
$stmtReq = $pdo->prepare("
    INSERT INTO rule_requirements (rule_id, requires_node, condition_expression)
    VALUES (?, ?, ?)
");

// Pour l'UE : Certificat d'usage final et licence d'exportation
$stmtReq->execute(['RULE_EU_DUAL_USE_8542', 'DOC_END_USER_STATEMENT', NULL]);
$stmtReq->execute(['RULE_EU_DUAL_USE_8542', 'LIC_EXPORT_PERMIT_APPROVED', NULL]);

// Pour la Chine : Licence préalable du Ministère du Commerce (MOFCOM)
$stmtReq->execute(['RULE_CN_CRITICAL_METALS_MOFCOM', 'LIC_MOFCOM_DUAL_USE', NULL]);
$stmtReq->execute(['RULE_CN_CRITICAL_METALS_MOFCOM', 'DOC_SECURITY_REVIEW_CN', NULL]);

// Pour les US EAR : Licence BIS
$stmtReq->execute(['RULE_US_EAR_RESTRICTION', 'LIC_BIS_AUTHORIZATION', NULL]);

// Antagonismes stricts
$stmtAntag = $pdo->prepare("
    INSERT INTO antagonisms (node_a, node_b, conflict_type, reason)
    VALUES (?, ?, ?, ?)
");

$stmtAntag->execute([
    'ENTITY_SANCTIONED',
    'LIC_EXPORT_PERMIT_APPROVED',
    'LEGAL_PROHIBITION',
    'Entité sous sanctions internationales (OFAC / UE / UN) : délivrance de licence proscrite.'
]);

$stmtAntag->execute([
    'ENTITY_SANCTIONED',
    'LIC_MOFCOM_DUAL_USE',
    'LEGAL_PROHIBITION',
    'Entité répertoriée sur liste d’entités non fiables (MOFCOM) ou sanctions internationales.'
]);

$stmtAntag->execute([
    'END_USE_MILITARY',
    'DOC_END_USER_STATEMENT_CIVIL',
    'MUTUAL_EXCLUSION',
    'Contradiction formelle : usage militaire déclaré avec attestation civile.'
]);

echo "Catalogue de règles multilatérales injecté avec succès.\n";
