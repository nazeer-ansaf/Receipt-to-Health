<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/analysis.php';
require_once __DIR__ . '/../includes/profile.php';
require_once __DIR__ . '/../includes/medical_records.php';

if (!has_app_access()) {
    header('Location: ../login.php');
    exit;
}

$mode = strtolower((string)($_GET['mode'] ?? 'final'));
$samplePath = ROOT_DIR . DIRECTORY_SEPARATOR . 'samples' . DIRECTORY_SEPARATOR . 'final_year_demo_receipt.txt';

if (!is_file($samplePath)) {
    $samplePath = ROOT_DIR . DIRECTORY_SEPARATOR . 'samples' . DIRECTORY_SEPARATOR . 'demo_receipt.txt';
}

$profile = load_user_health_profile();
$profile['household_name'] = $profile['household_name'] ?? 'Demo Household';
$profile['family_size'] = 4;
$profile['age_group'] = 'mixed';
$profile['conditions'] = array_values(array_unique(array_merge($profile['conditions'] ?? [], ['diabetes', 'hypertension'])));
$profile['health_notes'] = 'Demo: pregnant mother, diabetic father, one child, low salt diet, soda should be corrected from 3 to 1.';
$profile['family_members'] = [
    ['name' => 'Mother', 'age_group' => 'adult', 'conditions' => [], 'notes' => 'pregnant'],
    ['name' => 'Father', 'age_group' => 'adult', 'conditions' => ['diabetes', 'hypertension'], 'notes' => 'low salt'],
    ['name' => 'Child', 'age_group' => 'children', 'conditions' => [], 'notes' => 'child nutrition'],
];
save_user_health_profile($profile);

$familySize = (int)$profile['family_size'];
$ageGroup = (string)$profile['age_group'];
$conditions = $profile['conditions'];
$memberContext = family_member_context_text($profile);
$healthNotes = trim((string)$profile['health_notes'] . "\nFamily members: " . $memberContext);
$profileAnalysis = generate_health_profile_analysis($profile);
$medicalRecords = load_medical_records();

try {
    $result = run_python_analysis($samplePath, $familySize, $ageGroup, $conditions, $healthNotes);
} catch (Throwable $exception) {
    json_response(['error' => 'Demo analysis failed.', 'details' => $exception->getMessage()], 500);
}

$receiptId = 'demo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
$result['receipt_id'] = $receiptId;
$result['source_type'] = 'demo_mode';
$result['receipt_asset'] = [
    'web_path' => 'samples/' . basename($samplePath),
    'original_name' => basename($samplePath),
    'extension' => pathinfo($samplePath, PATHINFO_EXTENSION),
    'is_image' => false,
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
    'medical_record_titles' => [],
];
$result['profile_analysis'] = $profileAnalysis;

if ($mode === 'review') {
    save_ocr_draft($receiptId, [
        'source_path' => $samplePath,
        'source_web_path' => 'samples/' . basename($samplePath),
        'original_name' => basename($samplePath),
        'extension' => pathinfo($samplePath, PATHINFO_EXTENSION),
        'family_size' => $familySize,
        'age_group' => $ageGroup,
        'conditions' => $conditions,
        'health_notes' => $healthNotes,
        'analysis_result' => $result,
    ]);

    header('Location: ../ocr_review.php?draft=' . urlencode($receiptId));
    exit;
}

persist_analysis_result($result, $samplePath, current_user_id());
save_analysis_result($result, $receiptId);

header('Location: ../dashboard.php?id=' . urlencode($receiptId));
exit;
