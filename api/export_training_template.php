<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_admin_user()) {
    json_response(['error' => 'Admin access is required.'], 403);
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="receipt-to-health-training-template.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['receipt_line', 'label', 'category', 'sugar_g', 'saturated_fat_g', 'sodium_mg', 'fiber_g', 'risk', 'recommendation', 'aliases', 'alternatives']);
fputcsv($output, ['coca cola bottle 1.5l', 'soda', 'sugary drink', 39, 0, 45, 0, 'high sugar', 'Replace soda with water or unsweetened tea.', 'cola|soft drink|carbonated drink', 'water|unsweetened tea']);
fputcsv($output, ['pringles family pack', 'chips', 'snack', 1, 3, 220, 1, 'high sodium snack', 'Limit salty snacks and add fruit, nuts, or yogurt.', 'potato chips|crisps', 'unsalted nuts|fruit|plain yogurt']);
fputcsv($output, ['anchor fresh milk 1l', 'milk', 'dairy', 12, 3, 100, 0, 'moderate dairy sugar', 'Choose low-fat unsweetened milk when possible.', 'fresh milk|low fat milk', 'plain yogurt']);
fclose($output);

