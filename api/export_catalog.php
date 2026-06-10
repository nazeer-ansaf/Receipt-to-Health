<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalog.php';

if (!is_admin_user()) {
    json_response(['error' => 'Admin access is required.'], 403);
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="receipt-to-health-food-catalog.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['name', 'category', 'sugar_g', 'saturated_fat_g', 'sodium_mg', 'fiber_g', 'risk', 'recommendation', 'aliases', 'alternatives']);

foreach (food_catalog() as $food) {
    fputcsv($output, [
        $food['name'] ?? '',
        $food['category'] ?? '',
        $food['sugar_g'] ?? 0,
        $food['saturated_fat_g'] ?? 0,
        $food['sodium_mg'] ?? 0,
        $food['fiber_g'] ?? 0,
        $food['risk'] ?? '',
        $food['recommendation'] ?? '',
        implode(', ', array_map('strval', $food['aliases'] ?? [])),
        implode(', ', array_map('strval', $food['alternatives'] ?? [])),
    ]);
}

fclose($output);
