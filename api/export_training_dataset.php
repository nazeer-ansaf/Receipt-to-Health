<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_admin_user()) {
    json_response(['error' => 'Admin access is required.'], 403);
}

$datasetPath = DATA_DIR . DIRECTORY_SEPARATOR . 'training_food_items.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="receipt-to-health-training-dataset.csv"');

if (is_file($datasetPath)) {
    readfile($datasetPath);
    exit;
}

$output = fopen('php://output', 'w');
fputcsv($output, ['receipt_line', 'label', 'category', 'sugar_g', 'saturated_fat_g', 'sodium_mg', 'fiber_g', 'risk', 'recommendation', 'aliases', 'alternatives']);
fclose($output);

