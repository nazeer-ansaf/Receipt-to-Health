<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/results.php';

$format = strtolower((string)($_GET['format'] ?? 'json'));
$result = load_result((string)($_GET['id'] ?? 'latest'));

if (!$result) {
    json_response(['error' => 'Report not found.'], 404);
}

$fileName = 'receipt-to-health-' . ($result['_id'] ?? 'report');

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['section', 'name', 'value', 'detail']);
    fputcsv($output, ['score', 'health_score', $result['health_score']['score'] ?? '', $result['health_score']['label'] ?? '']);

    foreach (($result['per_person_nutrition'] ?? []) as $key => $value) {
        fputcsv($output, ['nutrition', $key, $value, 'per person']);
    }

    foreach (($result['items'] ?? []) as $item) {
        fputcsv($output, ['item', $item['name'] ?? '', $item['quantity'] ?? '', $item['risk'] ?? '']);
    }

    foreach (($result['recommendations'] ?? []) as $recommendation) {
        fputcsv($output, ['recommendation', 'advice', '', $recommendation]);
    }

    fclose($output);
    exit;
}

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $fileName . '.json"');
echo json_encode($result, JSON_PRETTY_PRINT);

