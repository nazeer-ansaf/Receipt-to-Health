<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

function result_path(string $resultId): string
{
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $resultId);
    return RESULT_DIR . DIRECTORY_SEPARATOR . $safeId . '.json';
}

function load_result(string $resultId = 'latest'): ?array
{
    $path = result_path($resultId);

    if (!is_file($path)) {
        $path = result_path('latest');
    }

    if (!is_file($path)) {
        return null;
    }

    $result = json_decode((string)file_get_contents($path), true);
    if (!is_array($result)) {
        return null;
    }

    $result['_id'] = basename($path, '.json');
    $result['_created_at'] = filemtime($path) ?: time();
    return $result;
}

function load_all_results(): array
{
    $files = glob(RESULT_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $files = array_values(array_filter($files, static fn($file) => basename($file) !== 'latest.json'));

    usort($files, static fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

    $results = [];
    foreach ($files as $file) {
        $result = json_decode((string)file_get_contents($file), true);
        if (!is_array($result)) {
            continue;
        }

        $result['_id'] = basename($file, '.json');
        $result['_created_at'] = filemtime($file) ?: time();
        $results[] = $result;
    }

    return $results;
}

function score_value(?array $result): float
{
    return (float)($result['health_score']['score'] ?? 0);
}

function result_item_count(?array $result): int
{
    return isset($result['items']) && is_array($result['items']) ? count($result['items']) : 0;
}

function anomaly_count(?array $result): int
{
    return isset($result['anomalies']) && is_array($result['anomalies']) ? count($result['anomalies']) : 0;
}

function category_distribution(array $items): array
{
    $distribution = [];

    foreach ($items as $item) {
        $category = (string)($item['category'] ?? 'unknown');
        $distribution[$category] = ($distribution[$category] ?? 0) + (float)($item['quantity'] ?? 1);
    }

    arsort($distribution);
    return $distribution;
}

function nutrient_targets(): array
{
    return [
        'sugar_g' => ['label' => 'Sugar', 'unit' => 'g', 'target' => 25.0, 'danger' => 70.0, 'type' => 'lower'],
        'saturated_fat_g' => ['label' => 'Saturated Fat', 'unit' => 'g', 'target' => 10.0, 'danger' => 25.0, 'type' => 'lower'],
        'sodium_mg' => ['label' => 'Sodium', 'unit' => 'mg', 'target' => 700.0, 'danger' => 2000.0, 'type' => 'lower'],
        'fiber_g' => ['label' => 'Fiber', 'unit' => 'g', 'target' => 10.0, 'danger' => 0.0, 'type' => 'higher'],
        'nutrient_diversity' => ['label' => 'Diversity', 'unit' => 'groups', 'target' => 6.0, 'danger' => 0.0, 'type' => 'higher'],
    ];
}

function nutrient_rows(array $perPerson): array
{
    $rows = [];

    foreach (nutrient_targets() as $key => $target) {
        $value = (float)($perPerson[$key] ?? 0);

        if ($target['type'] === 'lower') {
            $percent = min(100, ($value / max($target['danger'], 1)) * 100);
            $status = $value <= $target['target'] ? 'Within target' : ($value >= $target['danger'] ? 'High risk' : 'Elevated');
            $level = $value <= $target['target'] ? 'Low' : ($value >= $target['danger'] ? 'High' : 'Moderate');
        } else {
            $percent = min(100, ($value / max($target['target'], 1)) * 100);
            $status = $value >= $target['target'] ? 'Good' : 'Needs improvement';
            $level = $value >= $target['target'] ? 'Low' : ($value <= $target['danger'] ? 'High' : 'Moderate');
        }

        $rows[] = [
            'key' => $key,
            'label' => $target['label'],
            'value' => $value,
            'unit' => $target['unit'],
            'target' => $target['target'],
            'status' => $status,
            'level' => $level,
            'level_class' => risk_level_class($level),
            'percent' => round($percent, 1),
        ];
    }

    return $rows;
}

function risk_level_class(string $level): string
{
    $level = strtolower(trim($level));

    if ($level === 'high') {
        return 'risk-high';
    }
    if ($level === 'moderate') {
        return 'risk-moderate';
    }
    return 'risk-low';
}

function risk_text_class(string $risk): string
{
    $risk = strtolower($risk);

    if (str_contains($risk, 'high') || str_contains($risk, 'processed') || str_contains($risk, 'sodium') || str_contains($risk, 'sugar')) {
        return 'risk-high';
    }

    if (str_contains($risk, 'moderate') || str_contains($risk, 'refined') || str_contains($risk, 'sweetened') || str_contains($risk, 'hidden') || str_contains($risk, 'energy')) {
        return 'risk-moderate';
    }

    return 'risk-low';
}

function priority_class(string $priority): string
{
    $priority = strtolower(trim($priority));

    if ($priority === 'fix first') {
        return 'priority-fix';
    }
    if ($priority === 'watch') {
        return 'priority-watch';
    }
    return 'priority-good';
}

function score_explanation(array $result): array
{
    $stored = $result['score_explanation'] ?? null;

    if (is_array($stored) && isset($stored['summary'])) {
        return $stored;
    }

    $breakdown = $result['health_score']['breakdown'] ?? [];
    asort($breakdown);
    $weakest = array_slice($breakdown, 0, 3, true);

    return [
        'summary' => 'Score explanation is based on the lowest scoring components: ' . implode(', ', array_map(
            static fn($key, $value) => ucwords((string)$key) . ' ' . $value,
            array_keys($weakest),
            $weakest
        )) . '.',
        'reasons' => ['Older reports may not contain the newer detailed explanation fields.'],
        'weakest_components' => array_map(
            static fn($key, $value) => ['component' => (string)$key, 'score' => $value],
            array_keys($weakest),
            $weakest
        ),
    ];
}

function recommendation_cards(array $result): array
{
    $cards = [];

    foreach (($result['recommendation_proofs'] ?? []) as $card) {
        if (!is_array($card)) {
            continue;
        }

        $advice = trim((string)($card['advice'] ?? $card['recommendation'] ?? ''));

        if ($advice === '') {
            continue;
        }

        $cards[] = [
            'advice' => $advice,
            'trigger' => trim((string)($card['trigger'] ?? 'Rule-based recommendation')),
            'proof_points' => array_values(array_filter(array_map('strval', $card['proof_points'] ?? []))),
            'evidence_items' => array_values(array_filter(array_map('strval', $card['evidence_items'] ?? []))),
            'alternatives' => is_array($card['alternatives'] ?? null) ? $card['alternatives'] : [],
        ];
    }

    if ($cards) {
        return $cards;
    }

    return build_recommendation_cards_from_result($result);
}

function build_recommendation_cards_from_result(array $result): array
{
    $cards = [];
    $perPerson = $result['per_person_nutrition'] ?? [];
    $family = $result['family'] ?? [];
    $recommendations = $result['recommendations'] ?? [];

    foreach ($recommendations as $recommendation) {
        $advice = trim((string)$recommendation);

        if ($advice === '') {
            continue;
        }

        $lowerAdvice = strtolower($advice);
        $proofPoints = [];
        $evidenceItems = [];
        $trigger = 'Rule-based recommendation';

        if (str_contains($lowerAdvice, 'sugar')) {
            $trigger = 'Nutrient threshold';
            $proofPoints[] = recommendation_nutrient_proof($perPerson, 'sugar_g', 'Sugar', 25, 'g', 'or less');
            $evidenceItems = array_merge($evidenceItems, recommendation_item_evidence($result, ['soda', 'juice', 'cookies', 'chocolate', 'yogurt', 'cereal']));
        }

        if (str_contains($lowerAdvice, 'sodium') || str_contains($lowerAdvice, 'salt')) {
            $trigger = 'Nutrient threshold';
            $proofPoints[] = recommendation_nutrient_proof($perPerson, 'sodium_mg', 'Sodium', 700, 'mg', 'or less');
            $evidenceItems = array_merge($evidenceItems, recommendation_item_evidence($result, ['chips', 'noodles', 'sausages', 'sauce', 'cheese']));
        }

        if (str_contains($lowerAdvice, 'fiber')) {
            $trigger = 'Nutrient threshold';
            $proofPoints[] = recommendation_nutrient_proof($perPerson, 'fiber_g', 'Fiber', 8, 'g', 'or more');
        }

        if (str_contains($lowerAdvice, 'diabetes')) {
            $trigger = 'Condition and item match';
            $proofPoints[] = 'Diabetes risk was selected in the family conditions.';
            $evidenceItems = array_merge($evidenceItems, recommendation_item_evidence($result, ['soda', 'juice', 'cookies', 'chocolate']));
        }

        if (str_contains($lowerAdvice, 'hypertension')) {
            $trigger = 'Condition and item match';
            $proofPoints[] = 'Hypertension was selected in the family conditions.';
            $evidenceItems = array_merge($evidenceItems, recommendation_item_evidence($result, ['chips', 'noodles', 'sausages', 'sauce']));
        }

        if (str_contains($lowerAdvice, 'vegetable')) {
            $trigger = str_contains($lowerAdvice, 'add') ? 'Missing food group' : $trigger;
            $proofPoints[] = 'Vegetable presence is checked from normalized receipt items and category distribution.';
            $evidenceItems = array_merge($evidenceItems, recommendation_item_evidence($result, ['vegetables']));
        }

        if (str_contains($lowerAdvice, 'noodles')) {
            $trigger = 'Item risk rule';
            $proofPoints[] = 'Noodles are mapped as high sodium instant food in the nutrition graph.';
            $evidenceItems = array_merge($evidenceItems, recommendation_item_evidence($result, ['noodles']));
        }

        if (str_contains($lowerAdvice, 'processed meat') || str_contains($lowerAdvice, 'sausages')) {
            $trigger = 'Item risk rule';
            $proofPoints[] = 'Processed meat is mapped to sodium and saturated fat exposure.';
            $evidenceItems = array_merge($evidenceItems, recommendation_item_evidence($result, ['sausages']));
        }

        if (str_contains($lowerAdvice, 'sweet snacks are present')) {
            $trigger = 'Item risk rule';
            $proofPoints[] = 'Sweet snack items are identified from normalized receipt names and risk labels.';
            $evidenceItems = array_merge($evidenceItems, recommendation_item_evidence($result, ['cookies', 'chocolate']));
        }

        if (!$proofPoints) {
            $proofPoints[] = 'Generated from nutrient thresholds, family conditions, item risks, and anomaly output.';
            $proofPoints[] = 'Family context: ' . condition_text($family) . '.';
        }

        $cards[] = [
            'advice' => $advice,
            'trigger' => $trigger,
            'proof_points' => array_values(array_unique($proofPoints)),
            'evidence_items' => array_values(array_unique($evidenceItems)),
        ];
    }

    return $cards;
}

function recommendation_nutrient_proof(array $perPerson, string $key, string $label, float $target, string $unit, string $direction): string
{
    $value = round((float)($perPerson[$key] ?? 0), 2);
    return $label . ' is ' . $value . ' ' . $unit . ' per person; target is ' . $target . ' ' . $unit . ' ' . $direction . '.';
}

function recommendation_item_evidence(array $result, array $names): array
{
    $evidence = [];
    $nameSet = array_flip($names);

    foreach (($result['items'] ?? []) as $item) {
        $name = (string)($item['name'] ?? '');

        if (!isset($nameSet[$name])) {
            continue;
        }

        $quantity = (float)($item['quantity'] ?? 1);
        $category = (string)($item['category'] ?? 'unknown');
        $risk = (string)($item['risk'] ?? 'risk not stored');
        $rawLine = trim((string)($item['raw_line'] ?? ''));
        $rawEvidence = $rawLine !== '' ? '; receipt line: ' . $rawLine : '';
        $evidence[] = $name . ' detected, quantity ' . $quantity . ' (' . $category . ', ' . $risk . $rawEvidence . ')';
    }

    return $evidence;
}

function average_score(array $results): float
{
    if (!$results) {
        return 0.0;
    }

    $total = array_sum(array_map('score_value', $results));
    return round($total / count($results), 1);
}

function condition_text(array $family): string
{
    $conditions = $family['conditions'] ?? [];

    if (!is_array($conditions) || !$conditions) {
        return 'None selected';
    }

    return implode(', ', array_map(static fn($value) => ucwords(str_replace('_', ' ', (string)$value)), $conditions));
}

function health_score_class(float $score): string
{
    if ($score >= 80) {
        return 'score-good';
    }
    if ($score >= 65) {
        return 'score-mid';
    }
    if ($score >= 45) {
        return 'score-watch';
    }
    return 'score-risk';
}

function aggregate_nutrients(array $results): array
{
    $totals = [
        'sugar_g' => 0.0,
        'saturated_fat_g' => 0.0,
        'sodium_mg' => 0.0,
        'fiber_g' => 0.0,
        'nutrient_diversity' => 0.0,
    ];

    foreach ($results as $result) {
        foreach ($totals as $key => $value) {
            $totals[$key] += (float)($result['per_person_nutrition'][$key] ?? 0);
        }
    }

    if (!$results) {
        return $totals;
    }

    foreach ($totals as $key => $value) {
        $totals[$key] = round($value / count($results), 2);
    }

    return $totals;
}

function aggregate_categories(array $results): array
{
    $categories = [];

    foreach ($results as $result) {
        foreach (($result['items'] ?? []) as $item) {
            $category = (string)($item['category'] ?? 'unknown');
            $categories[$category] = ($categories[$category] ?? 0) + (float)($item['quantity'] ?? 1);
        }
    }

    arsort($categories);
    return $categories;
}

function aggregate_items(array $results): array
{
    $items = [];

    foreach ($results as $result) {
        foreach (($result['items'] ?? []) as $item) {
            $name = (string)($item['name'] ?? 'unknown');
            if (!isset($items[$name])) {
                $items[$name] = ['quantity' => 0.0, 'count' => 0, 'risk' => $item['risk'] ?? ''];
            }
            $items[$name]['quantity'] += (float)($item['quantity'] ?? 1);
            $items[$name]['count']++;
        }
    }

    uasort($items, static fn($a, $b) => $b['quantity'] <=> $a['quantity']);
    return $items;
}

function recommendation_frequency(array $results): array
{
    $frequency = [];

    foreach ($results as $result) {
        foreach (($result['recommendations'] ?? []) as $recommendation) {
            $frequency[$recommendation] = ($frequency[$recommendation] ?? 0) + 1;
        }
    }

    arsort($frequency);
    return $frequency;
}

function moving_average_scores(array $results, int $window = 3): array
{
    $ordered = array_reverse($results);
    $averages = [];

    foreach ($ordered as $index => $result) {
        $slice = array_slice($ordered, max(0, $index - $window + 1), $window);
        $averages[] = [
            'id' => $result['_id'],
            'date' => $result['_created_at'],
            'score' => score_value($result),
            'moving_average' => average_score($slice),
        ];
    }

    return $averages;
}

function weekly_score_trend(array $results, int $limit = 8): array
{
    $weeks = [];
    $ordered = array_reverse($results);

    foreach ($ordered as $result) {
        $timestamp = (int)($result['_created_at'] ?? time());
        $weekKey = date('o-\WW', $timestamp);

        if (!isset($weeks[$weekKey])) {
            $weeks[$weekKey] = [
                'key' => $weekKey,
                'label' => date('M j', strtotime('monday this week', $timestamp) ?: $timestamp),
                'scores' => [],
            ];
        }

        $weeks[$weekKey]['scores'][] = score_value($result);
    }

    $points = array_values($weeks);
    $points = array_slice($points, max(0, count($points) - $limit));

    foreach ($points as $index => $point) {
        $points[$index]['score'] = round(array_sum($point['scores']) / max(1, count($point['scores'])), 1);
        $points[$index]['count'] = count($point['scores']);
        unset($points[$index]['scores']);
    }

    return $points;
}

function database_counts(): array
{
    try {
        require_once __DIR__ . '/db.php';
        $pdo = db();
        $tables = ['receipts', 'receipt_items', 'health_scores', 'anomalies', 'recommendations', 'users', 'family_profiles'];
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }

        return $counts;
    } catch (Throwable $exception) {
        return ['error' => $exception->getMessage()];
    }
}
