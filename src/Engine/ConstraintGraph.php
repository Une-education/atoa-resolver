<?php
namespace AtoA\Resolver\Engine;

use PDO;

class ConstraintGraph {
    private PDO $pdo;
    private ?PDO $taricPdo = null;
    private ?PDO $ofacPdo = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;

        // Connexion TARIC
        $taricPath = '/var/www/customs-api/taric.sqlite';
        if (file_exists($taricPath) && is_readable($taricPath)) {
            try {
                $this->taricPdo = new PDO('sqlite:' . $taricPath, null, null, [
                    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
                ]);
            } catch (\Exception $e) { $this->taricPdo = null; }
        }

        // Connexion International / OFAC
        $ofacPath = '/var/www/atoa-api/international.sqlite';
        if (file_exists($ofacPath) && is_readable($ofacPath)) {
            try {
                $this->ofacPdo = new PDO('sqlite:' . $ofacPath, null, null, [
                    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY
                ]);
            } catch (\Exception $e) { $this->ofacPdo = null; }
        }
    }

    private function lookupHsCode(string $rawHs): ?array {
        if (!$this->taricPdo) return null;
        $cleanHs = preg_replace('/[^0-9]/', '', $rawHs);
        if (strlen($cleanHs) < 4) return null;

        try {
            $stmt = $this->taricPdo->prepare("
                SELECT code_taric, desc_fr, desc_en 
                FROM goods_nomenclature 
                WHERE code_taric LIKE :prefix OR sh6 = :sh6
                ORDER BY hier_pos DESC, length(code_taric) ASC LIMIT 1
            ");
            $stmt->execute([
                ':prefix' => $cleanHs . '%',
                ':sh6' => substr(str_pad($cleanHs, 6, '0'), 0, 6)
            ]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($match) {
                return [
                    'code_taric' => $match['code_taric'],
                    'desc_fr' => $match['desc_fr'] ?: $match['desc_en'],
                    'desc_en' => $match['desc_en']
                ];
            }
        } catch (\Exception $e) {}
        return null;
    }

    /**
     * Scanne les entités contre le corpus OFAC Title 31
     */
    private function scanEntities(array $entities): array {
        if (!$this->ofacPdo || empty($entities)) return [];
        $hits = [];

        foreach ($entities as $ent) {
            $entClean = trim($ent);
            if (strlen($entClean) < 3) continue;

            try {
                // Recherche trigramme FTS5 dans foreign_fts
                $stmt = $this->ofacPdo->prepare("
                    SELECT id, jurisdiction, code_name, article_num, title 
                    FROM foreign_fts 
                    WHERE foreign_fts MATCH :query 
                    LIMIT 2
                ");
                // Requête trigramme FTS5
                $safeQuery = '"' . str_replace('"', '""', $entClean) . '"';
                $stmt->execute([':query' => $safeQuery]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($results)) {
                    $hits[$entClean] = $results;
                }
            } catch (\Exception $e) {}
        }
        return $hits;
    }

    public function resolve(array $payload): array {
        $startTime = microtime(true);
        $origin = strtoupper(trim($payload['origin'] ?? '*'));
        $dest = strtoupper(trim($payload['destination'] ?? '*'));
        $rawHs = trim($payload['hs_code'] ?? '');
        $facts = $payload['facts'] ?? [];
        $entities = $payload['entities'] ?? [];

        // 1. Validation de nomenclature TARIC
        $nomenclature = $this->lookupHsCode($rawHs);

        // 2. Détection automatisée des sanctions sur les entités transmises
        $entityMatches = $this->scanEntities($entities);
        if (!empty($entityMatches) && !in_array('ENTITY_SANCTIONED', $facts)) {
            $facts[] = 'ENTITY_SANCTIONED';
        }

        // 3. Identification des règles actives
        $cleanHs = preg_replace('/[^0-9]/', '', $rawHs);
        $stmt = $this->pdo->prepare("
            SELECT * FROM rules 
            WHERE (origin = :origin OR origin = '*')
              AND (destination = :dest OR destination = '*')
              AND (:hs LIKE hs_pattern OR :rawHs LIKE hs_pattern)
        ");
        $stmt->execute([
            ':origin' => $origin,
            ':dest' => $dest,
            ':hs' => $cleanHs . '%',
            ':rawHs' => $rawHs . '%'
        ]);
        $activeRules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $requiredNodes = [];
        $ruleIds = [];

        foreach ($activeRules as $r) {
            $ruleIds[] = $r['rule_id'];
            $reqStmt = $this->pdo->prepare("SELECT requires_node FROM rule_requirements WHERE rule_id = ?");
            $reqStmt->execute([$r['rule_id']]);
            $reqs = $reqStmt->fetchAll(PDO::FETCH_COLUMN);
            $requiredNodes = array_unique(array_merge($requiredNodes, $reqs));
        }

        // 4. Conflits et antagonismes
        $conflicts = [];
        $antagStmt = $this->pdo->query("SELECT * FROM antagonisms");
        $antagonisms = $antagStmt->fetchAll(PDO::FETCH_ASSOC);

        $allActiveNodes = array_unique(array_merge($facts, $requiredNodes));

        foreach ($antagonisms as $a) {
            if (in_array($a['node_a'], $allActiveNodes) && in_array($a['node_b'], $allActiveNodes)) {
                $conflicts[] = [
                    'type' => $a['conflict_type'],
                    'nodes' => [$a['node_a'], $a['node_b']],
                    'reason' => $a['reason']
                ];
            }
        }

        // 5. Exigences manquantes
        $missingRequirements = array_diff($requiredNodes, $facts);

        $status = 'COMPLIANT';
        if (!empty($conflicts)) {
            $status = 'BLOCKED';
        } elseif (!empty($missingRequirements)) {
            $status = 'CONDITIONAL_MISSING_DOCS';
        }

        return [
            'status' => $status,
            'hs_classification' => $nomenclature ?? ['status' => 'NOT_FOUND_IN_TARIC'],
            'entities_screened' => [
                'count' => count($entities),
                'flagged' => !empty($entityMatches),
                'matches' => $entityMatches
            ],
            'rules_triggered' => $ruleIds,
            'missing_requirements' => array_values($missingRequirements),
            'conflicts' => $conflicts,
            'computation_time_ms' => round((microtime(true) - $startTime) * 1000, 3)
        ];
    }

    /**
     * Pipeline composite d'arbitrage commercial international (AtoA Meta-Resolver)
     */
    public function arbitrateTrade(array $payload): array {
        $origin = strtoupper(trim($payload['origin'] ?? 'CN'));
        $dest = strtoupper(trim($payload['destination'] ?? 'FR'));
        $rawHs = trim($payload['hs_code'] ?? '');
        $customsValue = max(0.0, (float)($payload['customs_value_eur'] ?? 0));
        $defectDiscoveredDate = $payload['defect_discovered_date'] ?? null;
        $deliveryDate = $payload['delivery_date'] ?? null;
        $entities = $payload['entities'] ?? [];

        // 1. Classification TARIC & Droits de douane
        $nomenclature = $this->lookupHsCode($rawHs);
        $duties = null;
        if ($this->taricPdo && $customsValue > 0) {
            $cleanHs = preg_replace('/[^0-9]/', '', $rawHs);
            $sh6 = substr($cleanHs, 0, 6);
            $sh4 = substr($cleanHs, 0, 4);
            $sh2 = substr($cleanHs, 0, 2);

            $stmtRate = $this->taricPdo->prepare("
                SELECT code_prefix, description, standard_rate, vat_rate, legal_basis
                FROM customs_duty_rates
                WHERE code_prefix IN (?, ?, ?)
                ORDER BY length(code_prefix) DESC LIMIT 1
            ");
            $stmtRate->execute([$sh6, $sh4, $sh2]);
            $rateData = $stmtRate->fetch(PDO::FETCH_ASSOC);

            $dutyRate = $rateData ? (float)$rateData['standard_rate'] : 3.5;
            $vatRate = $rateData ? (float)$rateData['vat_rate'] : 20.0;
            $dutyAmount = round($customsValue * ($dutyRate / 100), 2);
            $vatBase = round($customsValue + $dutyAmount, 2);
            $vatAmount = round($vatBase * ($vatRate / 100), 2);

            $duties = [
                'customs_value_eur' => $customsValue,
                'duty_rate_percent' => $dutyRate,
                'duty_amount_eur' => $dutyAmount,
                'vat_taxable_base_eur' => $vatBase,
                'vat_amount_eur' => $vatAmount,
                'total_taxes_eur' => round($dutyAmount + $vatAmount, 2),
                'legal_basis' => $rateData['legal_basis'] ?? 'EU Common Customs Tariff'
            ];
        }

        // 2. Criblage sanctions internationales (OFAC / UE)
        $sanctions = $this->scanEntities($entities);
        $hasSanctions = !empty($sanctions);

        // 3. Analyse des délais légaux et forclusion (Sino / CISG / FR)
        $proceduralStatus = 'COMPLIANT';
        $legalNotes = [];

        if ($deliveryDate && $defectDiscoveredDate) {
            $delivTs = strtotime($deliveryDate);
            $defectTs = strtotime($defectDiscoveredDate);
            $daysDiff = (int)round(($defectTs - $delivTs) / 86400);

            if ($origin === 'CN') {
                if ($daysDiff > 730) {
                    $proceduralStatus = 'FORCLOSED';
                    $legalNotes[] = 'Action forclose sous PRC Civil Code Art. 621 (délai butoir absolu de 2 ans dépassé).';
                } elseif ($daysDiff > 30) {
                    $legalNotes[] = 'Alerte CVIM/CISG Art. 39 : Risque de contestation sur le délai raisonnable d\'inspection.';
                } else {
                    $legalNotes[] = 'Notification conforme aux délais d\'inspection commerciale bilatérale.';
                }
            }

            if ($dest === 'FR') {
                $legalNotes[] = 'Sous réserve de conformité DGCCRF et garantie légale des vices cachés (Art. 1641 Code civil, 2 ans à compter de la découverte).';
            }
        }

        // 4. Détermination de la juridiction et loi applicable
        $applicableLaw = ($origin === 'CN' && $dest === 'FR') 
            ? 'Convention de Vienne (CVIM/CISG) - Droit supplétif chinois ou français selon clause attributive'
            : 'Règlement Rome I (UE) / Droit local du lieu de livraison';

        return [
            'pipeline_status' => $hasSanctions ? 'BLOCKED_SANCTION_RISK' : 'ARBITRATION_READY',
            'trade_axis' => "{$origin} -> {$dest}",
            'customs_and_tariff' => [
                'nomenclature' => $nomenclature,
                'liquidation' => $duties
            ],
            'compliance_and_sanctions' => [
                'entities_screened' => count($entities),
                'sanctions_detected' => $hasSanctions,
                'matches' => $sanctions
            ],
            'dispute_analysis' => [
                'procedural_status' => $proceduralStatus,
                'applicable_framework' => $applicableLaw,
                'legal_findings' => $legalNotes
            ]
        ];
    }
}
