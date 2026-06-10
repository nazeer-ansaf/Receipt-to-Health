<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/analysis.php';
require_once __DIR__ . '/../includes/profile.php';
require_once __DIR__ . '/../includes/medical_records.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Invalid request method.'], 405);
}

if (!has_app_access()) {
    json_response(['error' => 'Login or guest mode is required before analysis.'], 403);
}

$names = $_POST['item_name'] ?? [];
$quantities = $_POST['quantity'] ?? [];

if (!is_array($names) || !is_array($quantities)) {
    json_response(['error' => 'Corrected items are required.'], 422);
}

$lines = [];
$editedItems = [];

foreach ($names as $index => $name) {
    $cleanName = strtolower(trim((string)$name));
    $cleanName = preg_replace('/[^a-zA-Z0-9 _-]/', '', $cleanName);
    $cleanName = preg_replace('/\s+/', ' ', $cleanName ?? '');
    $quantity = max(0, (float)($quantities[$index] ?? 0));

    if ($cleanName === '' || $quantity <= 0) {
        continue;
    }

    $formattedQuantity = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    $lines[] = trim($cleanName . ' ' . $formattedQuantity);
    $editedItems[] = ['name' => $cleanName, 'quantity' => $quantity];
}

$extraText = trim((string)($_POST['extra_receipt_text'] ?? ''));
if ($extraText !== '') {
    $lines[] = $extraText;
}

if (!$lines) {
    json_response(['error' => 'At least one corrected item is required.'], 422);
}

$familySize = max(1, min(20, (int)($_POST['family_size'] ?? 1)));
$ageGroup = preg_replace('/[^a-zA-Z_-]/', '', $_POST['age_group'] ?? 'adult');
$conditions = $_POST['conditions'] ?? [];
$healthNotes = trim((string)($_POST['health_notes'] ?? ''));
$draftId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['draft_id'] ?? ''));

if (!is_array($conditions)) {
    $conditions = [];
}

$profile = load_user_health_profile();
$profile['family_size'] = $familySize;
$profile['age_group'] = $ageGroup;
$profile['conditions'] = sanitize_profile_conditions($conditions);

if ($healthNotes !== '') {
    $profile['health_notes'] = $healthNotes;
}

save_user_health_profile($profile);
$memberContext = family_member_context_text($profile);
$analysisHealthNotes = trim((string)($profile['health_notes'] ?? $healthNotes) . ($memberContext !== '' ? "\nFamily members: " . $memberContext : ''));
$profileAnalysis = generate_health_profile_analysis($profile);
$medicalRecords = load_medical_records();
$draft = $draftId !== '' ? load_ocr_draft($draftId) : null;

ensure_directory(UPLOAD_DIR);
ensure_directory(RESULT_DIR);

$receiptId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$textPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $receiptId . '_items_corrected.txt';
file_put_contents($textPath, implode(PHP_EOL, $lines) . PHP_EOL);

try {
    $result = run_python_analysis($textPath, $familySize, $ageGroup, $conditions, $analysisHealthNotes);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Python analysis failed.',
        'details' => $exception->getMessage(),
    ], 500);
}

$result['receipt_id'] = $receiptId;
$result['source_type'] = 'manual_item_correction';
$result['correction_context'] = [
    'draft_id' => $draftId,
    'edited_items' => $editedItems,
    'original_ocr_status' => $draft['analysis_result']['ocr_status'] ?? null,
    'original_extracted_text' => $draft['analysis_result']['extracted_text'] ?? '',
    'corrected_text' => implode(PHP_EOL, $lines),
    'original_asset' => [
        'web_path' => $draft['source_web_path'] ?? '',
        'original_name' => $draft['original_name'] ?? '',
        'extension' => $draft['extension'] ?? '',
        'is_image' => in_array((string)($draft['extension'] ?? ''), ['jpg', 'jpeg', 'png', 'webp'], true),
    ],
];
$result['profile_context'] = [
    'role' => current_user_role(),
    'guest_mode' => is_guest_user(),
    'household_name' => $profile['household_name'] ?? '',
    'diet_goal' => $profile['diet_goal'] ?? '',
    'activity_level' => $profile['activity_level'] ?? '',
    'health_notes' => $profile['health_notes'] ?? '',
    'family_members' => $profile['family_members'] ?? [],
    'medical_record_count' => count($medicalRecords),
    'medical_record_titles' => array_values(array_filter(array_map(
        static fn($record) => trim((string)($record['title'] ?? '')) ?: (string)($record['original_name'] ?? ''),
        array_slice($medicalRecords, 0, 5)
    ))),
];
$result['profile_analysis'] = $profileAnalysis;

persist_analysis_result($result, $textPath, current_user_id());
save_analysis_result($result, $receiptId);

header('Location: ../dashboard.php?id=' . urlencode($receiptId));
exit;
