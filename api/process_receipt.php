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

require_valid_csrf_token();

if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    json_response(['error' => 'Receipt upload failed.'], 422);
}

$familySize = max(1, min(20, (int)($_POST['family_size'] ?? 1)));
$ageGroup = preg_replace('/[^a-zA-Z_-]/', '', $_POST['age_group'] ?? 'adult');
$conditions = $_POST['conditions'] ?? [];
$healthNotes = trim((string)($_POST['health_notes'] ?? ''));
$reviewBeforeAnalysis = !empty($_POST['review_items']);

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

$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'txt'];
$allowedMimeTypes = [
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'webp' => ['image/webp'],
    'txt' => ['text/plain', 'text/csv', 'text/tab-separated-values'],
];
$maxReceiptBytes = 10 * 1024 * 1024;
$originalName = $_FILES['receipt']['name'];
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$sizeBytes = (int)($_FILES['receipt']['size'] ?? 0);
$temporaryPath = (string)($_FILES['receipt']['tmp_name'] ?? '');

if (!in_array($extension, $allowedExtensions, true)) {
    json_response(['error' => 'Only JPG, PNG, WEBP, and TXT receipts are allowed.'], 422);
}

if ($sizeBytes <= 0) {
    json_response(['error' => 'The selected receipt is empty.'], 422);
}

if ($sizeBytes > $maxReceiptBytes) {
    json_response(['error' => 'Receipt uploads must be 10 MB or smaller.'], 422);
}

$mimeType = 'application/octet-stream';

if ($temporaryPath !== '' && function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if ($finfo !== false) {
        $detectedMime = finfo_file($finfo, $temporaryPath);
        finfo_close($finfo);

        if (is_string($detectedMime) && $detectedMime !== '') {
            $mimeType = $detectedMime;
        }
    }
}

if ($mimeType !== 'application/octet-stream') {
    $mimeMatches = in_array($mimeType, $allowedMimeTypes[$extension] ?? [], true)
        || ($extension === 'txt' && str_starts_with($mimeType, 'text/'));

    if (!$mimeMatches) {
        json_response(['error' => 'The selected file type does not match the allowed receipt formats.'], 422);
    }
}

ensure_directory(UPLOAD_DIR);
ensure_directory(RESULT_DIR);

$receiptId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$uploadedPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $receiptId . '.' . $extension;

if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $uploadedPath)) {
    json_response(['error' => 'Could not save uploaded receipt.'], 500);
}

try {
    $result = run_python_analysis($uploadedPath, $familySize, $ageGroup, $conditions, $analysisHealthNotes);
} catch (Throwable $exception) {
    json_response([
        'error' => 'Python analysis failed.',
        'details' => $exception->getMessage(),
    ], 500);
}

$result['receipt_id'] = $receiptId;
$result['source_type'] = $reviewBeforeAnalysis ? 'ocr_draft_review' : ($extension === 'txt' ? 'text_upload' : 'image_upload');
$result['receipt_asset'] = [
    'web_path' => 'uploads/' . basename($uploadedPath),
    'original_name' => $originalName,
    'extension' => $extension,
    'mime_type' => $mimeType,
    'size_bytes' => $sizeBytes,
    'is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true),
    'sha256' => hash_file('sha256', $uploadedPath) ?: '',
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

if ($reviewBeforeAnalysis) {
    save_ocr_draft($receiptId, [
        'source_path' => $uploadedPath,
        'source_web_path' => 'uploads/' . basename($uploadedPath),
        'original_name' => $originalName,
        'extension' => $extension,
        'family_size' => $familySize,
        'age_group' => $ageGroup,
        'conditions' => sanitize_profile_conditions($conditions),
        'health_notes' => $analysisHealthNotes,
        'analysis_result' => $result,
    ]);

    header('Location: ../ocr_review.php?draft=' . urlencode($receiptId));
    exit;
}

persist_analysis_result($result, $uploadedPath, current_user_id());
save_analysis_result($result, $receiptId);

header('Location: ../dashboard.php?id=' . urlencode($receiptId));
exit;
