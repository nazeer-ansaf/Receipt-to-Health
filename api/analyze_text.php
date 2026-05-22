<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/analysis.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Invalid request method.'], 405);
}

$receiptText = trim((string)($_POST['receipt_text'] ?? ''));

if ($receiptText === '') {
    json_response(['error' => 'Receipt text is required.'], 422);
}

$familySize = max(1, min(20, (int)($_POST['family_size'] ?? 1)));
$ageGroup = preg_replace('/[^a-zA-Z_-]/', '', $_POST['age_group'] ?? 'adult');
$conditions = $_POST['conditions'] ?? [];

if (!is_array($conditions)) {
    $conditions = [];
}

ensure_directory(UPLOAD_DIR);
ensure_directory(RESULT_DIR);

$receiptId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$textPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $receiptId . '_corrected.txt';
file_put_contents($textPath, $receiptText);

try {
    $result = run_python_analysis($textPath, $familySize, $ageGroup, $conditions);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Python analysis failed.',
        'details' => $exception->getMessage(),
    ], 500);
}

$result['receipt_id'] = $receiptId;
$result['source_type'] = 'manual_ocr_correction';
persist_analysis_result($result, $textPath, current_user_id());
save_analysis_result($result, $receiptId);

header('Location: ../dashboard.php?id=' . urlencode($receiptId));
exit;
