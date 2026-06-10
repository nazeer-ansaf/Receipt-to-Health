<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/medical_records.php';

if (!has_app_access()) {
    json_response(['error' => 'Login or guest mode is required before downloading medical records.'], 403);
}

$record = find_medical_record((string)($_GET['id'] ?? ''), current_user());

if (!$record || !is_file((string)($record['path'] ?? ''))) {
    json_response(['error' => 'Medical record not found.'], 404);
}

$fileName = basename((string)($record['original_name'] ?? 'medical-record'));
$mimeType = (string)($record['mime_type'] ?? 'application/octet-stream');
$filePath = (string)$record['path'];

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
