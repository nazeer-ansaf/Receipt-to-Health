<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/analysis.php';
require_once __DIR__ . '/includes/external_datasets.php';

function ml_metrics_path(): string
{
    return ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'food_classifier_metrics.json';
}

function ml_model_info_path(): string
{
    return ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'food_classifier_model.json';
}

function ml_dataset_path(): string
{
    return DATA_DIR . DIRECTORY_SEPARATOR . 'training_food_items.csv';
}

const ADMIN_CSV_UPLOAD_LIMIT_BYTES = 10 * 1024 * 1024;

function ml_model_status(array $modelInfo): array
{
    $datasetPath = ml_dataset_path();
    $catalogPath = food_catalog_path();
    $generatedPath = DATA_DIR . DIRECTORY_SEPARATOR . 'generated_training_variants.csv';
    $currentDatasetHash = is_file($datasetPath) ? hash_file('sha256', $datasetPath) : '';
    $currentCatalogHash = is_file($catalogPath) ? hash_file('sha256', $catalogPath) : '';
    $currentGeneratedHash = is_file($generatedPath) ? hash_file('sha256', $generatedPath) : '';
    $schemaVersion = 'grouped-evaluation-v2';
    $modelPath = ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'food_classifier.joblib';
    $current = is_file($modelPath)
        && ($modelInfo['raw_dataset_hash'] ?? ($modelInfo['dataset_hash'] ?? '')) === $currentDatasetHash
        && ($modelInfo['catalog_hash'] ?? '') === $currentCatalogHash
        && ($modelInfo['generated_dataset_hash'] ?? '') === $currentGeneratedHash
        && ($modelInfo['trainer_schema_version'] ?? '') === $schemaVersion;

    return ['current' => $current, 'dataset_hash' => $currentDatasetHash, 'catalog_hash' => $currentCatalogHash, 'generated_dataset_hash' => $currentGeneratedHash, 'schema_version' => $schemaVersion];
}

function load_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function promote_file_atomically(string $stagingPath, string $targetPath): void
{
    $backupPath = $targetPath . '.backup.' . bin2hex(random_bytes(4));
    $hadTarget = is_file($targetPath);
    if ($hadTarget && !rename($targetPath, $backupPath)) {
        throw new RuntimeException('Could not protect the existing file before promotion.');
    }

    try {
        if (!rename($stagingPath, $targetPath)) {
            throw new RuntimeException('Could not promote the validated file.');
        }
        if ($hadTarget) {
            @unlink($backupPath);
        }
    } catch (Throwable $exception) {
        @unlink($targetPath);
        if ($hadTarget && is_file($backupPath)) {
            @rename($backupPath, $targetPath);
        }
        throw $exception;
    }
}

function run_food_model_training(string $datasetPath): array
{
    if (!is_file($datasetPath)) {
        throw new RuntimeException('Feedback dataset does not exist yet. Save OCR corrections first.');
    }

    $scriptPath = ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'train_food_model.py';
    $command = escapeshellcmd(PYTHON_COMMAND) . ' '
        . escapeshellarg($scriptPath)
        . ' --dataset ' . escapeshellarg($datasetPath)
        . ' 2>&1';

    $output = shell_exec($command);
    $decoded = json_decode(trim((string)$output), true);

    if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'trained') {
        throw new RuntimeException('ML training failed: ' . trim((string)$output));
    }

    return $decoded;
}

function train_uploaded_ml_dataset(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Dataset upload failed. CSV files must be smaller than 10 MB.');
    }

    if ((int)($file['size'] ?? 0) > ADMIN_CSV_UPLOAD_LIMIT_BYTES) {
        throw new RuntimeException('Training CSV is too large. The maximum allowed size is 10 MB.');
    }
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
        throw new RuntimeException('Training upload must be a CSV file.');
    }

    $handle = fopen((string)$file['tmp_name'], 'r');

    if (!$handle) {
        throw new RuntimeException('Could not read uploaded dataset.');
    }

    $headers = fgetcsv($handle);
    fclose($handle);

    if (!is_array($headers)) {
        throw new RuntimeException('Dataset CSV is empty.');
    }

    $headers = array_map(static fn($value) => strtolower(trim((string)$value)), $headers);
    $requiredHeaders = ['receipt_line', 'label'];
    $missingHeaders = array_values(array_diff($requiredHeaders, $headers));

    if ($missingHeaders) {
        throw new RuntimeException('Dataset missing required column(s): ' . implode(', ', $missingHeaders));
    }

    ensure_directory(DATA_DIR);
    $datasetPath = DATA_DIR . DIRECTORY_SEPARATOR . 'training_food_items.csv';
    $stagingPath = DATA_DIR . DIRECTORY_SEPARATOR . '.training_food_items.' . bin2hex(random_bytes(8)) . '.csv';

    if (!move_uploaded_file((string)$file['tmp_name'], $stagingPath)) {
        throw new RuntimeException('Could not stage uploaded dataset.');
    }

    try {
        $result = run_food_model_training($stagingPath);
        $promotePath = $datasetPath . '.promote.' . bin2hex(random_bytes(4));
        if (!copy($stagingPath, $promotePath)) {
            throw new RuntimeException('Training succeeded, but the validated dataset could not be staged for promotion.');
        }
        promote_file_atomically($promotePath, $datasetPath);
        return $result;
    } finally {
        @unlink($stagingPath);
    }
}

function train_current_feedback_dataset(): array
{
    return run_food_model_training(DATA_DIR . DIRECTORY_SEPARATOR . 'training_food_items.csv');
}

function evaluate_real_holdout(): array
{
    $scriptPath = ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'evaluate_real_holdout.py';
    $command = escapeshellcmd(PYTHON_COMMAND) . ' ' . escapeshellarg($scriptPath) . ' --json 2>&1';
    $output = shell_exec($command);
    $decoded = json_decode(trim((string)$output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Holdout evaluation failed: ' . trim((string)$output));
    }
    return $decoded;
}

function import_real_holdout(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Holdout CSV upload failed. CSV files must be smaller than 10 MB.');
    }
    if ((int)($file['size'] ?? 0) > ADMIN_CSV_UPLOAD_LIMIT_BYTES) {
        throw new RuntimeException('Holdout CSV is too large. The maximum allowed size is 10 MB.');
    }
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
        throw new RuntimeException('Holdout upload must be a CSV file.');
    }
    $handle = fopen((string)$file['tmp_name'], 'r');
    if (!$handle) {
        throw new RuntimeException('Could not read holdout CSV.');
    }
    $headers = array_map(static fn($value) => strtolower(trim((string)$value)), fgetcsv($handle) ?: []);
    $required = ['receipt_line', 'label', 'category', 'receipt_id', 'store', 'source', 'verified', 'notes'];
    $missing = array_values(array_diff($required, $headers));
    $rows = 0;
    while (fgetcsv($handle) !== false) {
        $rows++;
    }
    fclose($handle);
    if ($missing) {
        throw new RuntimeException('Holdout CSV missing required column(s): ' . implode(', ', $missing));
    }
    ensure_directory(DATA_DIR);
    $holdoutPath = real_holdout_path();
    $stagingPath = $holdoutPath . '.import.' . bin2hex(random_bytes(8));
    if (!copy((string)$file['tmp_name'], $stagingPath)) {
        @unlink($stagingPath);
        throw new RuntimeException('Could not promote holdout CSV safely.');
    }
    promote_file_atomically($stagingPath, $holdoutPath);
    return ['rows' => $rows, 'headers' => $headers];
}

function holdout_report_is_eligible(array $result): bool
{
    $sourceType = strtolower(trim((string)($result['source_type'] ?? '')));
    $asset = $result['receipt_asset'] ?? [];
    $correctionAsset = $result['correction_context']['original_asset'] ?? [];
    $hasOriginalAsset = (!empty($asset['original_name']) && !empty($asset['sha256']))
        || (!empty($correctionAsset['original_name']) && !empty($correctionAsset['web_path']));
    $hasReviewableHistoricalText = $sourceType === '' && trim((string)($result['extracted_text'] ?? '')) !== '';
    return ($sourceType !== '' || $hasReviewableHistoricalText)
        && $sourceType !== 'demo_mode'
        && ($hasOriginalAsset || $hasReviewableHistoricalText)
        && !empty($result['items'])
        && is_array($result['items'])
        && !empty($result['receipt_id']);
}

function holdout_catalog_by_label(): array
{
    $byLabel = [];
    foreach (food_catalog() as $food) {
        $label = normalize_training_feedback_text((string)($food['name'] ?? ''));
        if ($label !== '') {
            $byLabel[$label] = $food;
        }
    }
    return $byLabel;
}

if (!is_admin_user()) {
    render_page_start('Admin Access', 'admin');
    page_hero(
        'Restricted area',
        'Admin Access Required',
        'This console is available only to admin accounts.',
        '<a class="button primary" href="profile_setup.php">Back to profile</a>'
    );
    ?>
    <section class="panel">
        <h2>Current Session</h2>
        <p class="muted">You are signed in as <?= e(ucfirst(current_user_role())) ?>. Sign in with an admin account to open the data integrity console.</p>
    </section>
    <?php
    render_page_end();
    exit;
}

$adminMessage = '';
$adminError = '';
$holdoutEvaluation = null;
$holdoutReviewResult = null;
$holdoutReviewId = '';
$holdoutReviewConflicts = [];
$holdoutPromotionCompleted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestBytes = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postedAction = (string)($_POST['external_action'] ?? $_POST['catalog_action'] ?? $_POST['user_action'] ?? $_POST['ml_action'] ?? $_POST['holdout_action'] ?? '');
    if ($requestBytes > ADMIN_CSV_UPLOAD_LIMIT_BYTES + (1024 * 1024) && !str_starts_with($postedAction, 'external_')) {
        $adminError = 'Upload request is too large. CSV uploads are limited to 10 MB.';
    }
    $action = $adminError === ''
        ? $postedAction
        : '';

    try {
        if ($adminError !== '') {
            throw new RuntimeException($adminError);
        }
        if (!is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token expired. Please refresh the page and try again.');
        }

        if ($action === 'external_upload') {
            $relative = external_save_upload($_FILES['external_file'] ?? []);
            external_create_local_job($relative);
            $adminMessage = 'External file accepted and queued for inspection.';
        } elseif ($action === 'external_cancel') {
            external_change_job_status(external_safe_id((string)($_POST['job_id'] ?? '')), 'Cancelled');
            $adminMessage = 'External import cancellation requested.';
        } elseif ($action === 'external_retry') {
            external_retry_job(external_safe_id((string)($_POST['job_id'] ?? '')));
            $adminMessage = 'External import retry queued.';
        } elseif ($action === 'external_local_job') {
            external_create_local_job((string)($_POST['external_path'] ?? ''));
            $adminMessage = 'External file queued for inspection.';
        } elseif ($action === 'external_kaggle') {
            $slug = external_parse_kaggle_slug((string)($_POST['kaggle_dataset'] ?? ''));
            external_start_job(['provider' => 'kaggle', 'slug' => $slug, 'source_path' => '', 'title' => $slug, 'source_url' => 'https://www.kaggle.com/datasets/' . $slug]);
            $adminMessage = 'Kaggle dataset queued. Credentials are never displayed or stored in the job metadata.';
        } elseif ($action === 'external_review') {
            $jobId = external_safe_id((string)($_POST['job_id'] ?? '')); $job = external_read_json(external_job_path($jobId));
            if (!$job) throw new RuntimeException('Import job not found.');
            if (($job['status'] ?? '') !== 'Ready for Review') throw new RuntimeException('This import is not ready for candidate review.');
            external_review_candidate($job, (int)($_POST['candidate_index'] ?? -1), (string)($_POST['decision'] ?? ''), (string)($_POST['label'] ?? ''));
            $adminMessage = 'External candidate review saved.';
        } elseif ($action === 'external_bulk_review') {
            $jobId = external_safe_id((string)($_POST['job_id'] ?? '')); $job = external_read_json(external_job_path($jobId));
            if (!$job) throw new RuntimeException('Import job not found.');
            if (($job['status'] ?? '') !== 'Ready for Review') throw new RuntimeException('This import is not ready for candidate review.');
            $decision = (string)($_POST['decision'] ?? '');
            $indices = array_map('intval', (array)($_POST['candidate_indices'] ?? []));
            if (!$indices) throw new RuntimeException('Select at least one candidate first.');
            $done = 0; $failed = [];
            foreach ($indices as $index) {
                try { external_review_candidate($job, $index, $decision, (string)($_POST['bulk_label'] ?? '')); $done++; }
                catch (Throwable $reviewException) { $failed[] = $reviewException->getMessage(); }
            }
            $adminMessage = $done . ' candidate(s) marked ' . $decision . '.' . ($failed ? ' ' . count($failed) . ' could not be changed because of duplicate/conflict checks.' : '');
        } elseif ($action === 'create_user') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = normalize_account_role((string)($_POST['role'] ?? 'user'));
            $matchingLogin = $name !== '' ? find_user_by_login_identifier($name) : null;

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
                throw new RuntimeException('Enter a username, valid email, and password with at least 6 characters.');
            }

            if (find_user_by_email($email)) {
                throw new RuntimeException('An account already exists for this email.');
            }

            if ($matchingLogin && strcasecmp((string)($matchingLogin['name'] ?? ''), $name) === 0) {
                throw new RuntimeException('An account already exists for this username.');
            }

            register_user($name, $email, $password, $role);
            $adminMessage = ucfirst($role) . ' account created for ' . $email . '.';
        } elseif ($action === 'save') {
            upsert_food_catalog_item($_POST, (string)($_POST['original_name'] ?? ''));
            $adminMessage = 'Food catalog item saved. New analyses will use the updated nutrient values and rules.';
        } elseif ($action === 'delete') {
            delete_food_catalog_item((string)($_POST['original_name'] ?? $_POST['name'] ?? ''));
            $adminMessage = 'Food catalog item deleted.';
        } elseif ($action === 'import_csv') {
            if (!isset($_FILES['catalog_csv']) || $_FILES['catalog_csv']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('CSV upload failed.');
            }

            $handle = fopen($_FILES['catalog_csv']['tmp_name'], 'r');
            if (!$handle) {
                throw new RuntimeException('Could not read uploaded CSV.');
            }

            $headers = fgetcsv($handle);
            if (!is_array($headers)) {
                throw new RuntimeException('CSV file is empty.');
            }

            $headers = array_map(static fn($value) => strtolower(trim((string)$value)), $headers);
            $importCount = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $payload = [];

                foreach ($headers as $index => $header) {
                    $payload[$header] = $row[$index] ?? '';
                }

                if (trim((string)($payload['name'] ?? '')) === '') {
                    continue;
                }

                upsert_food_catalog_item($payload, (string)($payload['name'] ?? ''));
                $importCount++;
            }

            fclose($handle);
            $adminMessage = $importCount . ' food catalog row(s) imported from CSV.';
        } elseif ($action === 'train_ml_dataset') {
            $trainingResult = train_uploaded_ml_dataset($_FILES['ml_dataset_csv'] ?? []);
            $adminMessage = 'ML dataset validated and trained. Item accuracy: '
                . ($trainingResult['accuracy'] ?? 'n/a')
                . ', category accuracy: '
                . ($trainingResult['category_accuracy'] ?? 'n/a')
                . ', skipped rows: '
                . ($trainingResult['skipped_rows'] ?? 0)
                . '.';
        } elseif ($action === 'retrain_current_feedback') {
            $trainingResult = train_current_feedback_dataset();
            $adminMessage = 'ML model retrained from the current feedback dataset. Item accuracy: '
                . ($trainingResult['accuracy'] ?? 'n/a')
                . ', category accuracy: '
                . ($trainingResult['category_accuracy'] ?? 'n/a')
                . ', skipped rows: '
                . ($trainingResult['skipped_rows'] ?? 0)
                . '.';
        } elseif ($action === 'review_training_candidate') {
            $decision = (string)($_POST['decision'] ?? '');
            if (!in_array($decision, ['approve', 'reject'], true)) {
                throw new RuntimeException('Choose approve or reject.');
            }
            $review = review_training_candidate((int)($_POST['candidate_index'] ?? -1), $decision);
            $adminMessage = ucfirst($decision) . 'd candidate: ' . ($review['receipt_line'] ?? '') . '.';
        } elseif ($action === 'import_real_holdout') {
            $holdout = import_real_holdout($_FILES['holdout_csv'] ?? []);
            $adminMessage = 'Evaluation-only holdout imported with ' . $holdout['rows'] . ' row(s). No model retraining was started.';
        } elseif ($action === 'evaluate_real_holdout') {
            $holdoutEvaluation = evaluate_real_holdout();
            file_put_contents(DATA_DIR . DIRECTORY_SEPARATOR . 'real_receipt_holdout_evaluation.json', json_encode($holdoutEvaluation, JSON_PRETTY_PRINT));
            $adminMessage = 'Holdout evaluation completed with status: ' . ($holdoutEvaluation['status'] ?? 'unknown') . '.';
        } elseif ($action === 'save_verified_holdout') {
            $holdoutReviewId = trim((string)($_POST['receipt_id'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $holdoutReviewId) || !is_file(result_path($holdoutReviewId))) {
                throw new RuntimeException('Receipt report could not be found.');
            }
            $holdoutReviewResult = load_result($holdoutReviewId);
            if (!is_array($holdoutReviewResult) || !holdout_report_is_eligible($holdoutReviewResult)) {
                throw new RuntimeException('This receipt is not eligible for genuine holdout review.');
            }
            $labelMap = holdout_catalog_by_label();
            $selected = [];
            foreach ((array)($_POST['holdout_include'] ?? []) as $rawIndex) {
                $index = (int)$rawIndex;
                $label = normalize_training_feedback_text((string)($_POST['holdout_label'][$index] ?? ''));
                $line = trim((string)($_POST['holdout_line'][$index] ?? ''));
                if ($line === '' || !isset($labelMap[$label])) {
                    continue;
                }
                $food = $labelMap[$label];
                $selected[] = [
                    'receipt_line' => $line,
                    'label' => $label,
                    'category' => normalize_training_feedback_text((string)($food['category'] ?? $_POST['holdout_category'][$index] ?? '')),
                    'receipt_id' => $holdoutReviewId,
                    'store' => trim((string)($holdoutReviewResult['receipt_metadata']['store_name'] ?? '')),
                    'source' => 'existing_receipt',
                    'verified' => '1',
                    'notes' => 'Admin-verified existing receipt line; detection method: ' . trim((string)($holdoutReviewResult['items'][$index]['detection_method'] ?? 'reviewed')),
                ];
            }
            if (!$selected) {
                throw new RuntimeException('Select at least one valid food line to promote.');
            }
            $holdoutReviewConflicts = holdout_candidate_conflicts($selected);
            if ($holdoutReviewConflicts) {
                $conflictingLines = [];
                foreach ($holdoutReviewConflicts as $conflict) {
                    $conflictingLines[normalize_training_feedback_text((string)($conflict['receipt_line'] ?? ''))] = true;
                }
                $cleanSelected = array_values(array_filter($selected, static fn($row) => !isset($conflictingLines[normalize_training_feedback_text((string)($row['receipt_line'] ?? ''))])));
                if (!$cleanSelected) {
                    throw new RuntimeException('Holdout promotion blocked: all selected lines overlap existing training or holdout evidence. Exclude or correct the flagged lines before trying again.');
                }
                $excludedCount = count($selected) - count($cleanSelected);
                $selected = $cleanSelected;
            } else {
                $excludedCount = 0;
            }
            $added = append_verified_holdout_rows($holdoutReviewId, $selected);
            $adminMessage = $added . ' verified holdout row(s) saved.' . ($excludedCount > 0 ? ' ' . $excludedCount . ' contaminated line(s) were excluded.' : '') . ' No training or retraining was performed.';
            $holdoutPromotionCompleted = true;
            $holdoutReviewResult = null;
        }
    } catch (Throwable $exception) {
        $adminError = $exception->getMessage();
    }
    // Prevent browser refreshes from replaying an external import POST and creating duplicate jobs.
    if ($adminError === '' && str_starts_with($action, 'external_')) {
        header('Location: admin.php#external-datasets', true, 303);
        exit;
    }
}

$counts = database_counts();
$externalJobs = external_list_jobs();
$externalVisibleJobs = array_slice($externalJobs, 0, 8);
$externalHistoryJobs = array_slice($externalJobs, 8);
$externalScannedRows = array_sum(array_map(static fn($job) => (int)($job['progress']['rows_scanned'] ?? 0), $externalJobs));
$externalCandidateRows = array_sum(array_map(static fn($job) => (int)($job['candidate_count'] ?? 0), $externalJobs));
$externalInbox = external_list_inbox_files();
$results = load_all_results();
$eligibleHoldoutReports = array_values(array_filter($results, 'holdout_report_is_eligible'));
$promotedHoldoutReportIds = holdout_receipt_ids();
$excludedHoldoutReports = array_values(array_filter($results, static fn(array $result): bool => !holdout_report_is_eligible($result)));
$catalog = food_catalog();
$catalogCategories = food_catalog_categories();
$catalogTopCategories = $catalogCategories;
arsort($catalogTopCategories);
$catalogRiskRules = array_values(array_filter($catalog, static function ($food): bool {
    if (!is_array($food)) {
        return false;
    }

    $riskClass = risk_text_class((string)($food['risk'] ?? ''));
    return in_array($riskClass, ['risk-high', 'risk-moderate'], true);
}));
$catalogWithAlternatives = array_values(array_filter($catalog, static function ($food): bool {
    if (!is_array($food)) {
        return false;
    }

    return !empty($food['alternatives']) && is_array($food['alternatives']);
}));
$catalogPreview = array_slice($catalog, 0, 8);
$mlMetrics = load_json_file(ml_metrics_path());
$mlModelInfo = load_json_file(ml_model_info_path());
$mlStatus = ml_model_status($mlModelInfo);
$mlModelPath = ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'food_classifier.joblib';
$mlSourceCounts = is_array($mlMetrics['source_counts'] ?? null) ? $mlMetrics['source_counts'] : [];
$mlSourceTotal = max(1, array_sum(array_map('intval', $mlSourceCounts)));
$mlModelVersion = (string)($mlModelInfo['version'] ?? '');
$mlModelTimestamp = (string)($mlModelInfo['trained_at'] ?? '');
$mlModelDisplayVersion = $mlModelTimestamp !== '' && strtotime($mlModelTimestamp) !== false
    ? date('Y-m-d H:i', strtotime($mlModelTimestamp)) . ' UTC'
    : ($mlModelVersion !== '' ? substr($mlModelVersion, 0, 16) : 'n/a');
$mlDatasetRows = 0;
$mlDatasetPath = ml_dataset_path();
if (is_file($mlDatasetPath) && ($handle = fopen($mlDatasetPath, 'r'))) {
    fgetcsv($handle);
    while (fgetcsv($handle) !== false) {
        $mlDatasetRows++;
    }
    fclose($handle);
}
$pendingTrainingCandidates = 0;
$candidatePath = training_candidate_path();
if (is_file($candidatePath) && ($handle = fopen($candidatePath, 'r'))) {
    fgetcsv($handle);
    while (fgetcsv($handle) !== false) {
        $pendingTrainingCandidates++;
    }
    fclose($handle);
}
$candidateRecords = candidate_records();
$holdoutReviewId = $holdoutReviewId !== '' ? $holdoutReviewId : trim((string)($_GET['holdout_review'] ?? ''));
if ($holdoutReviewResult === null && $holdoutReviewId !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $holdoutReviewId) && is_file(result_path($holdoutReviewId))) {
    $holdoutReviewResult = load_result($holdoutReviewId);
}
$holdoutRecords = read_csv_records(real_holdout_path());
$holdoutDemoRecords = read_csv_records(ROOT_DIR . DIRECTORY_SEPARATOR . 'samples' . DIRECTORY_SEPARATOR . 'holdout_demo.csv');
$holdoutIsDemo = !$holdoutRecords && !empty($holdoutDemoRecords);
$holdoutDisplayRecords = $holdoutIsDemo ? $holdoutDemoRecords : $holdoutRecords;
$holdoutRequiredFields = ['receipt_line', 'label', 'category', 'receipt_id', 'store', 'source', 'verified', 'notes'];
$holdoutValidationStatus = $holdoutIsDemo ? 'Demo preview' : 'Not imported';
if ($holdoutRecords) {
    $holdoutValidationStatus = count(array_filter($holdoutRequiredFields, static function (string $field) use ($holdoutRecords): bool {
        return !array_key_exists($field, $holdoutRecords[0]);
    })) === 0
        ? (count(array_filter($holdoutRecords, static fn($row) => trim((string)($row['receipt_line'] ?? '')) === '' || trim((string)($row['label'] ?? '')) === '')) === 0 ? 'Valid' : 'Invalid rows')
        : 'Missing required fields';
}
$holdoutEvaluationPath = DATA_DIR . DIRECTORY_SEPARATOR . 'real_receipt_holdout_evaluation.json';
if ($holdoutEvaluation === null && is_file($holdoutEvaluationPath)) {
    $holdoutEvaluation = load_json_file($holdoutEvaluationPath);
}
$holdoutContaminationStatus = $holdoutIsDemo ? 'Demo preview' : 'Not checked';
if ($holdoutPromotionCompleted) {
    $holdoutContaminationStatus = 'Clear';
} elseif ($holdoutEvaluation !== null) {
    $holdoutContaminationStatus = !empty($holdoutEvaluation['contamination_free']) ? 'Clear' : 'Contamination detected';
}
$holdoutValidation = is_array($holdoutEvaluation['validation'] ?? null) ? $holdoutEvaluation['validation'] : [];
$holdoutPhysicalRows = $holdoutIsDemo ? count($holdoutDisplayRecords) : (int)($holdoutEvaluation['physical_rows'] ?? count($holdoutRecords));
$holdoutVerifiedRows = $holdoutIsDemo ? count($holdoutDisplayRecords) : (int)($holdoutEvaluation['verified_rows'] ?? count(array_filter($holdoutRecords, static fn($row) => in_array(strtolower(trim((string)($row['verified'] ?? ''))), ['1','true','yes','verified'], true))));
$holdoutStatus = $holdoutIsDemo
    ? 'Demo preview - import real data'
    : (count($holdoutRecords) === 0
        ? 'Insufficient real holdout data'
        : ($holdoutContaminationStatus === 'Contamination detected'
            ? 'Contamination detected'
            : ((!empty($holdoutValidation['skipped_rows']) || (($holdoutEvaluation['unverified_rows'] ?? 0) > 0) || $holdoutValidationStatus !== 'Valid')
                ? 'Validation failed'
                : 'Ready')));
$mlSkippedRows = is_array($mlMetrics['skipped_rows'] ?? null) ? $mlMetrics['skipped_rows'] : [];
$recentResults = array_slice($results, 0, 6);
$averageReportScore = average_score($results);
$lowScoreReports = array_values(array_filter($results, static fn(array $result): bool => score_value($result) < 65));
$databaseLinkedReports = array_values(array_filter($results, static fn(array $result): bool => !empty($result['database_receipt_id'])));
$latestReport = $results[0] ?? null;
$registeredUsers = [];

try {
    $registeredUsers = list_registered_users();
} catch (Throwable $exception) {
    $adminError = $adminError !== '' ? $adminError : 'User list unavailable: ' . $exception->getMessage();
}

render_page_start('Admin Evidence', 'admin');
page_hero(
    'Admin panel',
    'Admin Control Panel',
    'Manage accounts, maintain the food catalog, and review backend evidence from a role-gated workspace.',
        '<a class="button primary" href="#manage-users">Manage users</a><a class="button ghost" href="#ml-dataset">ML dataset</a><a class="button ghost" href="#real-holdout">Real holdout</a><a class="button ghost" href="#manage-food-database">Food database</a><a class="button ghost" href="setup_check.php">Run setup check</a>'
);
?>

<?php if ($adminMessage !== ''): ?>
    <p class="success-text"><?= e($adminMessage) ?></p>
<?php endif; ?>
<?php if ($adminError !== ''): ?>
    <p class="warning-text"><?= e($adminError) ?></p>
<?php endif; ?>

<section class="score-band">
    <article class="metric"><span>JSON reports</span><strong><?= count($results) ?></strong><small>files saved</small></article>
    <article class="metric"><span>Food catalog</span><strong><?= count($catalog) ?></strong><small>records</small></article>
    <article class="metric" title="<?= e(PYTHON_COMMAND) ?>"><span>Python</span><strong>Ready</strong><small>configured analysis runtime</small></article>
    <article class="metric"><span>Database</span><strong><?= isset($counts['error']) ? 'Issue' : 'OK' ?></strong><small>MySQL connection</small></article>
</section>

<section class="panel">
    <h2>Admin Features</h2>
    <div class="module-list">
        <div><strong>User panel</strong><span>Create user or admin accounts without exposing role selection on public registration.</span></div>
        <div><strong>Catalog panel</strong><span>Edit nutrition values, aliases, risks, and recommendations used by receipt analysis.</span></div>
        <div><strong>Evidence panel</strong><span>Inspect database counts, generated reports, and system readiness for demonstrations.</span></div>
    </div>
</section>

<section class="grid two">
    <article class="panel">
        <h2>Database Table Counts</h2>
        <?php if (isset($counts['error'])): ?>
            <p class="warning-text"><?= e($counts['error']) ?></p>
        <?php else: ?>
            <div class="category-grid">
                <?php foreach ($counts as $table => $count): ?>
                    <div><span><?= e($table) ?></span><strong><?= e($count) ?></strong></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel">
        <h2>Implementation Checklist</h2>
        <div class="check-stack">
            <div><b>Done</b><span>Receipt upload and text analysis</span></div>
            <div><b>Done</b><span>Seven-layer AI dashboard evidence</span></div>
            <div><b>Done</b><span>MySQL result persistence</span></div>
            <div><b>Done</b><span>OCR correction workflow</span></div>
            <div><b>Done</b><span>Report export and printable report</span></div>
            <div><b>Done</b><span>Register/login/logout, roles, guest mode, and user-linked receipts</span></div>
            <div><b>Done</b><span>Real image OCR path with Tesseract and EasyOCR fallback</span></div>
            <div><b>Done</b><span>Admin food catalog editing for nutrients and recommendation rules</span></div>
            <div><b>Done</b><span>ML training, validation, metrics, versioning, and category fallback</span></div>
        </div>
    </article>
</section>

<section id="ml-dataset" class="panel admin-workspace-panel">
    <div class="admin-section-head">
        <div>
            <h2>ML Dataset and Model</h2>
            <p class="muted">Upload a labelled CSV to validate rows, retrain the item classifier, sync category/nutrition metadata, and save a versioned model.</p>
        </div>
        <div class="form-actions">
            <a class="button ghost" href="api/export_training_dataset.php">Download dataset</a>
            <a class="button ghost" href="api/export_training_template.php">Download template</a>
            <a class="button ghost" href="#real-holdout">Real-world holdout</a>
        </div>
    </div>

    <div class="admin-summary-grid">
        <div><span>Model status</span><strong><?= $mlStatus['current'] ? 'Current' : 'Stale' ?></strong><small><?= e($mlStatus['current'] ? 'matches active data and trainer' : 'retrain before trusting metrics') ?></small></div>
        <div><span>Training examples</span><strong><?= e($mlModelInfo['effective_dataset_rows'] ?? $mlMetrics['effective_dataset_rows'] ?? 'n/a') ?></strong><small>active effective examples</small></div>
        <div><span>Food labels</span><strong><?= e($mlModelInfo['label_count'] ?? 0) ?></strong><small>canonical classes</small></div>
        <div><span>Categories</span><strong><?= e($mlModelInfo['category_count'] ?? 0) ?></strong><small>food groups</small></div>
        <div><span>Train / validation / test</span><strong><?= e(($mlModelInfo['train_rows'] ?? 0) . ' / ' . ($mlModelInfo['validation_rows'] ?? 0) . ' / ' . ($mlModelInfo['test_rows'] ?? 0)) ?></strong><small>group-aware split</small></div>
        <div><span>Item accuracy</span><strong><?= e(is_numeric($mlMetrics['accuracy'] ?? null) ? number_format((float)$mlMetrics['accuracy'] * 100, 2) . '%' : 'n/a') ?></strong><small>held-out test split</small></div>
        <div><span>Macro F1</span><strong><?= e(is_numeric($mlMetrics['macro_f1'] ?? null) ? number_format((float)$mlMetrics['macro_f1'] * 100, 2) . '%' : 'n/a') ?></strong><small>held-out test split</small></div>
        <div><span>Category accuracy</span><strong><?= e(is_numeric($mlMetrics['category_accuracy'] ?? null) ? number_format((float)$mlMetrics['category_accuracy'] * 100, 2) . '%' : 'n/a') ?></strong><small>held-out test split</small></div>
        <div><span>Category Macro F1</span><strong><?= e(is_numeric($mlMetrics['category_test']['macro_f1'] ?? null) ? number_format((float)$mlMetrics['category_test']['macro_f1'] * 100, 2) . '%' : 'n/a') ?></strong><small>held-out test split</small></div>
        <div title="Full model version: <?= e($mlModelVersion) ?>"><span>Model version</span><strong><?= e($mlModelDisplayVersion) ?></strong><small><?= e($mlModelInfo['trained_at'] ?? 'not trained') ?></small></div>
        <div><span>External records scanned</span><strong><?= e(number_format($externalScannedRows)) ?></strong><small>isolated; not automatically trained</small></div>
        <div><span>External candidates</span><strong><?= e(number_format($externalCandidateRows)) ?></strong><small>Admin review required</small></div>
    </div>

    <?php if (!$mlStatus['current']): ?>
        <div class="warning-text">The saved model is stale. The active CSV, food catalog, or trainer schema differs from the model metadata. Metrics are not presented as current; retrain manually when ready.</div>
    <?php endif; ?>
    <details class="admin-technical-list">
        <summary>Technical details and dataset provenance</summary>
        <p class="muted">Training examples include manual, catalog-derived, synthetic, and OCR-augmented receipt text. Provenance is retained for reproducibility and evaluation.</p>
        <div class="admin-summary-grid">
            <div><span>Dataset hash</span><strong><?= e(substr((string)$mlStatus['dataset_hash'], 0, 12)) ?></strong><small>raw approved CSV</small></div>
            <div><span>Catalog hash</span><strong><?= e(substr((string)$mlStatus['catalog_hash'], 0, 12)) ?></strong><small>Food Catalog</small></div>
            <div><span>Generated hash</span><strong><?= e(substr((string)$mlStatus['generated_dataset_hash'], 0, 12)) ?></strong><small>generated variants</small></div>
            <div><span>Trainer schema</span><strong><?= e($mlStatus['schema_version']) ?></strong><small>evaluation protocol</small></div>
            <div><span>Raw/manual rows</span><strong><?= e($mlDatasetRows) ?></strong><small>physical approved CSV rows</small></div>
            <div><span>Catalog aliases</span><strong><?= e($mlMetrics['catalog_derived_examples'] ?? 'n/a') ?></strong><small>catalog-derived examples</small></div>
            <div><span>Synthetic examples</span><strong><?= e($mlMetrics['generated_source_counts']['synthetic'] ?? 0) ?></strong><small>generated receipt text</small></div>
            <div><span>OCR-augmented examples</span><strong><?= e($mlMetrics['generated_source_counts']['ocr_augmented'] ?? 0) ?></strong><small>controlled OCR variants</small></div>
            <div><span>User-feedback examples</span><strong><?= e($mlSourceCounts['user_feedback'] ?? 0) ?></strong><small>approved provenance</small></div>
            <div><span>Variant groups</span><strong><?= e($mlModelInfo['group_count'] ?? $mlMetrics['group_count'] ?? 'n/a') ?></strong><small>group-aware evidence</small></div>
            <div><span>Pending feedback</span><strong><?= e($pendingTrainingCandidates) ?></strong><small>quarantined, not trained</small></div>
            <div><span>Model file size</span><strong><?= e(is_file($mlModelPath) ? number_format(filesize($mlModelPath) / 1024, 1) . ' KB' : 'n/a') ?></strong><small>serialized classifier</small></div>
            <div><span>Unknown examples</span><strong><?= e($mlMetrics['unknown_evaluation']['total'] ?? 'n/a') ?></strong><small>open-set evaluation</small></div>
            <div><span>Unknown false acceptance</span><strong><?= e(is_numeric($mlMetrics['unknown_evaluation']['false_acceptance_rate'] ?? null) ? number_format((float)$mlMetrics['unknown_evaluation']['false_acceptance_rate'] * 100, 2) . '%' : 'n/a') ?></strong><small>expanded open-set evaluation</small></div>
        </div>
        <p class="muted">Source distribution: <?= e(implode(' · ', array_map(static fn($source, $count) => $source . ': ' . $count . ' (' . number_format(((int)$count / $mlSourceTotal) * 100, 1) . '%)', array_keys($mlSourceCounts), array_values($mlSourceCounts)))) ?></p>
    </details>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="ml_action" value="train_ml_dataset">
        <label class="upload-box compact-upload" data-empty-label="No dataset selected">
            <span>Upload training CSV</span>
            <input type="file" name="ml_dataset_csv" accept=".csv,text/csv" data-csv-upload data-max-bytes="10485760" required>
            <small>Required: receipt_line, label. Optional: category, sugar_g, saturated_fat_g, sodium_mg, fiber_g, risk, recommendation, aliases, alternatives.</small>
        </label>
        <button class="button primary" type="submit">Validate and train ML model</button>
    </form>

    <form method="post" class="inline-admin-action">
        <?= csrf_field() ?>
        <input type="hidden" name="ml_action" value="retrain_current_feedback">
        <button class="button ghost" type="submit">Retrain approved training dataset</button>
    </form>
    <p class="muted">OCR corrections are stored as pending candidates in <code>data/training_candidates.csv</code>. They are duplicate/conflict checked but are not included in training until an administrator promotes them into the approved dataset.</p>

    <?php if ($mlSkippedRows): ?>
        <details class="admin-technical-list" open>
            <summary>Dataset rows skipped during latest training</summary>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Receipt line</th><th>Label</th><th>Reason</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($mlSkippedRows, 0, 25) as $skippedRow): ?>
                            <tr>
                                <td><?= e($skippedRow['receipt_line'] ?? '') ?></td>
                                <td><?= e($skippedRow['label'] ?? '') ?></td>
                                <td><?= e($skippedRow['reason'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php else: ?>
        <p class="muted">Latest dataset validation has no skipped rows.</p>
    <?php endif; ?>
<section id="external-datasets" class="panel admin-workspace-panel">
    <div class="admin-section-head">
        <div>
            <h2>External Dataset Import</h2>
            <p class="muted">Kaggle, browser uploads, and administrator-staged large files are inspected in isolated workspaces. Importing never retrains the model or changes the real holdout.</p>
        </div>
        <strong><?= e(count($externalJobs)) ?> job(s)</strong>
    </div>
    <div class="grid three">
        <form class="admin-tool-box" method="post">
            <?= csrf_field() ?><input type="hidden" name="external_action" value="external_kaggle">
            <h3>Kaggle dataset</h3><label>URL or owner/dataset slug<input name="kaggle_dataset" placeholder="username/dataset-name" required></label>
            <button class="button primary" type="submit">Queue Kaggle import</button>
            <small class="muted">Uses the official Kaggle API/CLI credentials. If absent, the job reports: Kaggle authentication is not configured.</small>
        </form>
        <form class="admin-tool-box" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="external_action" value="external_upload">
            <h3>Small local upload</h3><label>CSV, TSV, ZIP, JSON, JSONL, or TXT<input type="file" name="external_file" accept=".csv,.tsv,.zip,.json,.jsonl,.txt" required></label>
            <button class="button primary" type="submit">Queue upload</button><small class="muted">Browser upload limit: 1 GB; actual PHP limits still apply.</small>
        </form>
        <div class="admin-tool-box">
            <h3>Large local file</h3>
            <?php if ($adminError !== ''): ?><p class="warning-text"><?= e($adminError) ?></p><?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="external_action" value="external_upload">
                <label>Browse from File Manager<input type="file" name="external_file" accept=".csv,.tsv,.zip,.json,.jsonl,.txt" required></label>
                <button class="button primary" type="submit">Upload and queue file</button>
            </form>
            <form method="post" class="inline-admin-action" style="margin-top:10px">
                <?= csrf_field() ?><input type="hidden" name="external_action" value="external_local_job">
                <label>Or choose an already staged file<select name="external_path" required><option value="">Choose a file</option><?php foreach ($externalInbox as $file): ?><option value="<?= e($file['name']) ?>"><?= e($file['name']) ?> (<?= e(number_format($file['size'] / 1024 / 1024, 1)) ?> MB)</option><?php endforeach; ?></select></label>
                <button class="button ghost" type="submit">Queue staged file</button>
            </form>
            <?php if (!$externalInbox): ?><small class="warning-text">No staged files found. You can use Browse above, or copy a very large file into data/external_imports/.</small><?php endif; ?>
            <small class="muted">This server currently allows browser uploads up to <?= e((string)ini_get('upload_max_filesize')) ?>. For the Kaggle OpenFoodFacts file, copy it to data/external_imports/ and use the staged-file selector.</small>
        </div>
    </div>
    <p class="muted">Large-file support is subject to available disk space, operating-system limits, Kaggle/API behavior, and configured safety limits. Batch size: <?= e(EXTERNAL_BATCH_SIZE) ?>; archive limit: <?= e(number_format(EXTERNAL_MAX_ARCHIVE_BYTES / 1024 / 1024 / 1024, 1)) ?> GB; extracted-data limit: <?= e(number_format(EXTERNAL_MAX_EXTRACTED_BYTES / 1024 / 1024 / 1024, 1)) ?> GB.</p>
    <?php if ($externalJobs): ?><div class="table-wrap"><table><thead><tr><th>Dataset</th><th>Provider</th><th>Status</th><th>Progress</th><th>Type / license</th></tr></thead><tbody>
        <?php foreach ($externalVisibleJobs as $job): $progress = is_array($job['progress'] ?? null) ? $job['progress'] : []; $jobCandidates = external_candidate_records($job, 50); $jobFinished = in_array($job['status'] ?? '', ['Ready for Review','Completed'], true); $progressPercent = $jobFinished ? 100 : (((int)($progress['bytes_total'] ?? 0) > 0) ? min(100, max(0, (int)round(((int)($progress['bytes_processed'] ?? 0) / (int)$progress['bytes_total']) * 100))) : null); $filesTotal = (int)($progress['files_total'] ?? ($progress['files'] ?? 0)); $filesProcessed = $jobFinished ? $filesTotal : (int)($progress['files_processed'] ?? 0); ?>
        <tr><td><?= e($job['title'] ?? $job['source_name'] ?? $job['id']) ?><br><small><?= e($job['id']) ?></small></td><td><?= e($job['provider'] ?? 'local') ?></td><td><strong><?= e($job['status'] ?? 'Unknown') ?></strong><br><small><?= e($job['step'] ?? '') ?><?php if (!empty($job['error'])): ?> · <?= e($job['error']) ?><?php endif; ?></small></td><td><?php if ($progressPercent !== null): ?><progress max="100" value="<?= e($progressPercent) ?>"></progress> <strong><?= e($progressPercent) ?>%</strong><br><?php else: ?><span class="muted">Working…</span><br><?php endif; ?><?= e(($progress['rows_scanned'] ?? 0) . ' rows scanned · ' . ($progress['accepted_rows'] ?? 0) . ' candidates · ' . $filesProcessed . '/' . $filesTotal . ' files') ?></td><td><?= e($job['dataset_type'] ?? 'pending inspection') ?><br><small>License: <?= e($job['license'] ?? 'Unclear / not supplied') ?></small><br><form method="post" class="inline-admin-action"><?= csrf_field() ?><input type="hidden" name="job_id" value="<?= e($job['id']) ?>"><?php if (in_array($job['status'] ?? '', ['Queued','Downloading','Extracting','Inspecting','Processing'], true)): ?><input type="hidden" name="external_action" value="external_cancel"><button class="button ghost" type="submit">Cancel</button><?php elseif (($job['status'] ?? '') === 'Failed'): ?><input type="hidden" name="external_action" value="external_retry"><button class="button ghost" type="submit">Retry</button><?php elseif (($job['status'] ?? '') === 'Ready for Review'): ?><input type="hidden" name="external_action" value="external_retry"><button class="button ghost" type="submit">Reprocess labels</button><?php endif; ?></form></td></tr>
        <?php if (($job['status'] ?? '') === 'Ready for Review' && $jobCandidates): ?><tr><td colspan="5"><details><summary>Review candidates (showing up to <?= e(count($jobCandidates)) ?>)</summary><form id="bulk-review-<?= e($job['id']) ?>" method="post" class="inline-admin-action"><?= csrf_field() ?><input type="hidden" name="external_action" value="external_bulk_review"><input type="hidden" name="job_id" value="<?= e($job['id']) ?>"><label><input type="checkbox" data-bulk-select-all="<?= e($job['id']) ?>"> Select all visible</label> <input name="bulk_label" placeholder="Optional label for all selected"><button class="button primary" name="decision" value="approve" type="submit">Approve selected</button><button class="button ghost" name="decision" value="reject" type="submit">Reject selected</button><button class="button ghost" name="decision" value="unknown" type="submit">Mark unknown</button></form><div class="table-wrap"><table><thead><tr><th>Select</th><th>Source text</th><th>Suggested label</th><th>Category / brand</th><th>Provenance / individual action</th></tr></thead><tbody><?php foreach ($jobCandidates as $candidate): ?><tr><td><input type="checkbox" name="candidate_indices[]" value="<?= e($candidate['candidate_index'] ?? 0) ?>" data-bulk-target="<?= e($job['id']) ?>" form="bulk-review-<?= e($job['id']) ?>"></td><td><?= e($candidate['source_text'] ?? '') ?></td><td><?= e($candidate['proposed_label'] ?? '') ?></td><td><?= e($candidate['source_category'] ?? '') ?><?= !empty($candidate['brand']) ? ' · ' . e($candidate['brand']) : '' ?></td><td><?= e($candidate['source_file'] ?? '') ?><br><small><?= e($candidate['source_provider'] ?? '') ?> · <?= e($candidate['verification_status'] ?? 'unverified') ?></small><br><form method="post" class="inline-admin-action"><?= csrf_field() ?><input type="hidden" name="external_action" value="external_review"><input type="hidden" name="job_id" value="<?= e($job['id']) ?>"><input type="hidden" name="candidate_index" value="<?= e($candidate['candidate_index'] ?? 0) ?>"><input name="label" value="<?= e($candidate['proposed_label'] ?? '') ?>" placeholder="label"><button class="button ghost" name="decision" value="approve" type="submit">One approve</button></form></td></tr><?php endforeach; ?></tbody></table></div></details></td></tr><?php endif; ?>
        <?php endforeach; ?></tbody></table></div><?php if ($externalHistoryJobs): ?><details class="admin-technical-list"><summary>Older job history (<?= e(count($externalHistoryJobs)) ?>)</summary><p class="muted">Older completed/import history is collapsed to keep the Admin page short. The current/recent jobs remain above.</p><?php foreach ($externalHistoryJobs as $oldJob): ?><div><strong><?= e($oldJob['title'] ?? $oldJob['id']) ?></strong> · <?= e($oldJob['status'] ?? 'Unknown') ?> · <?= e($oldJob['id']) ?></div><?php endforeach; ?></details><?php endif; ?><?php else: ?><p class="muted">No external imports yet.</p><?php endif; ?>
    <details class="admin-technical-list"><summary>Safety and isolation details</summary><p class="muted">Sources are hashed and retained in per-dataset workspaces. ZIP paths are checked for traversal, absolute paths, file-count and expansion limits. Records become unverified JSONL candidates; only explicit Admin approval can append evidence to the approved training CSV. External imports never enter data/real_receipt_holdout.csv.</p></details>
</section>
<?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && array_filter($externalJobs, static fn($job) => in_array($job['status'] ?? '', ['Queued','Downloading','Extracting','Inspecting','Processing'], true))): ?><script>setTimeout(function(){ window.location.reload(); }, 5000);</script><?php endif; ?>
<script>
document.querySelectorAll('[data-bulk-select-all]').forEach(function (master) {
    master.addEventListener('change', function () {
        document.querySelectorAll('[data-bulk-target="' + master.getAttribute('data-bulk-select-all') + '"]').forEach(function (box) {
            box.checked = master.checked;
        });
    });
});
</script>
<div id="real-holdout" class="admin-workspace-panel holdout-panel">
    <div class="admin-section-head">
        <div>
            <h2>Real-world Holdout Evaluation</h2>
            <p class="muted">Evaluation-only evidence. These rows are never loaded by training, alias generation, augmentation, or threshold tuning.</p>
        </div>
        <strong><?= e(count($holdoutDisplayRecords)) ?> row(s)</strong>
    </div>
    <div class="admin-summary-grid">
        <div><span>Holdout status</span><strong><?= e($holdoutStatus) ?></strong><small>evaluation-only dataset</small></div>
        <div><span>Physical holdout rows</span><strong><?= e($holdoutPhysicalRows) ?></strong><small>uploaded records</small></div>
        <div><span>Verified rows</span><strong><?= e($holdoutVerifiedRows) ?></strong><small><?= $holdoutIsDemo ? 'demo sample rows' : 'verified evidence' ?></small></div>
        <div><span>Validation status</span><strong><?= e($holdoutValidationStatus) ?></strong><small>required fields and row values</small></div>
        <div><span>Contamination status</span><strong><?= e($holdoutContaminationStatus) ?></strong><small>training and catalog comparison</small></div>
        <div><span>Unique receipts</span><strong><?= e(count(array_unique(array_filter(array_map(static fn($row) => trim((string)($row['receipt_id'] ?? '')), $holdoutDisplayRecords))))) ?></strong><small>receipt references</small></div>
        <div><span>Stores</span><strong><?= e(count(array_unique(array_filter(array_map(static fn($row) => trim((string)($row['store'] ?? '')), $holdoutDisplayRecords))))) ?></strong><small>represented stores</small></div>
        <div><span>Labels represented</span><strong><?= e(count(array_unique(array_filter(array_map(static fn($row) => trim((string)($row['label'] ?? '')), $holdoutDisplayRecords))))) ?></strong><small>canonical labels</small></div>
        <div><span>Categories represented</span><strong><?= e(count(array_unique(array_filter(array_map(static fn($row) => trim((string)($row['category'] ?? '')), $holdoutDisplayRecords))))) ?></strong><small>food categories</small></div>
    </div>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="ml_action" value="import_real_holdout">
        <label class="upload-box compact-upload" data-empty-label="No holdout CSV selected">
            <span>Import real holdout CSV</span>
            <input type="file" name="holdout_csv" accept=".csv,text/csv" data-csv-upload data-max-bytes="10485760" required>
            <small>Required: receipt_line, label, category, receipt_id, store, source, verified, notes.</small>
        </label>
        <button class="button primary" type="submit">Validate and import holdout</button>
    </form>
    <form method="post" class="inline-admin-action">
        <?= csrf_field() ?>
        <input type="hidden" name="ml_action" value="evaluate_real_holdout">
        <button class="button ghost" type="submit">Run Holdout Evaluation</button>
    </form>
    <?php $holdoutView = $holdoutEvaluation ?? []; ?>
    <?php if (count($holdoutRecords) === 0): ?>
        <div class="holdout-empty-state">
            <div class="holdout-empty-icon" aria-hidden="true">*</div>
            <div>
                <h3>Evaluation set is ready for genuine receipt evidence</h3>
                <p class="muted">No rows are shown yet because this dataset accepts only verified, previously unseen receipt lines. This keeps the real-world score honest and prevents training leakage.</p>
                <div class="holdout-checklist" aria-label="Holdout import checklist">
                    <span><b>1</b> Collect new receipts</span>
                    <span><b>2</b> Label each line</span>
                    <span><b>3</b> Verify and import CSV</span>
                    <span><b>4</b> Run evaluation</span>
                </div>
                <div class="holdout-empty-actions">
                    <a class="button ghost" href="samples/holdout_demo.csv" download>Download sample CSV</a>
                    <a class="button ghost" href="docs/ml_training.md#real-receipt-holdout" target="_blank" rel="noopener">Read data rules</a>
                </div>
                <p class="holdout-fixture-note">The downloadable file is an illustrative fixture only. It is not loaded into this evaluation and must not be presented as real evidence.</p>
            </div>
        </div>
    <?php endif; ?>
    <?php if (count($holdoutRecords) === 0 && $holdoutDemoRecords): ?>
        <div class="holdout-demo-preview">
            <div class="holdout-demo-head">
                <div>
                    <h3>Project demo data preview</h3>
                    <p class="muted">Sample rows created inside this project for UI testing. They are not counted as real holdout evidence.</p>
                </div>
                <span class="demo-badge">DEMO ONLY</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Receipt line</th><th>Label</th><th>Category</th><th>Store</th><th>Verification</th></tr></thead>
                    <tbody>
                    <?php foreach ($holdoutDemoRecords as $demoRow): ?>
                        <tr>
                            <td><?= e($demoRow['receipt_line'] ?? '') ?></td>
                            <td><?= e($demoRow['label'] ?? '') ?></td>
                            <td><?= e($demoRow['category'] ?? '') ?></td>
                            <td><?= e($demoRow['store'] ?? '') ?></td>
                            <td><span class="demo-status">Illustrative</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($holdoutReviewConflicts): ?>
        <p class="warning-text">The last holdout review excluded <?= e(count($holdoutReviewConflicts)) ?> contaminated or overlapping line(s); only clean verified lines were saved.</p>
    <?php endif; ?>
    <?php if ($holdoutView): ?>
        <p class="muted">Last evaluation: <?= e($holdoutView['status'] ?? 'unknown') ?> · <?= e($holdoutContaminationStatus) ?>.</p>
        <?php if ($holdoutValidation): ?>
            <details class="admin-technical-list">
                <summary>Validation details</summary>
                <p class="muted">Valid rows: <?= e($holdoutValidation['valid_rows'] ?? 0) ?> · Skipped rows: <?= e($holdoutValidation['skipped_rows'] ?? 0) ?> · Duplicate rows: <?= e(count($holdoutValidation['duplicate_rows'] ?? [])) ?> · Conflicting labels: <?= e(count($holdoutValidation['conflicting_labels'] ?? [])) ?> · Invalid/unverified rows: <?= e(($holdoutView['unverified_rows'] ?? 0) + count($holdoutValidation['invalid_rows'] ?? [])) ?>.</p>
            </details>
        <?php endif; ?>
        <?php if (!empty($holdoutView['contamination'])): ?>
            <details class="admin-technical-list">
                <summary>Training contamination details</summary>
                <p class="muted">Training contamination: <?= !empty($holdoutView['contamination_free']) ? 'None detected' : 'Detected' ?> · exact overlaps: <?= e(count($holdoutView['contamination']['exact_matches'] ?? [])) ?> · near-duplicate overlaps: <?= e(count($holdoutView['contamination']['strong_near_duplicates'] ?? [])) ?> · conflicting mappings: <?= e(count($holdoutView['contamination']['conflicting_mappings'] ?? [])) ?>.</p>
            </details>
        <?php endif; ?>
        <?php if (!empty($holdoutView['holdout_metrics'])): ?>
            <div class="admin-summary-grid">
                <div><span>Item accuracy</span><strong><?= e($holdoutView['holdout_metrics']['accuracy'] ?? 'n/a') ?></strong><small>real holdout</small></div>
                <div><span>Macro precision</span><strong><?= e($holdoutView['holdout_metrics']['macro_precision'] ?? 'n/a') ?></strong><small>real holdout</small></div>
                <div><span>Macro recall</span><strong><?= e($holdoutView['holdout_metrics']['macro_recall'] ?? 'n/a') ?></strong><small>real holdout</small></div>
                <div><span>Macro F1</span><strong><?= e($holdoutView['holdout_metrics']['macro_f1'] ?? 'n/a') ?></strong><small>real holdout</small></div>
                <div><span>Weighted F1</span><strong><?= e($holdoutView['holdout_metrics']['weighted_f1'] ?? 'n/a') ?></strong><small>real holdout</small></div>
                <div><span>Category accuracy</span><strong><?= e($holdoutView['holdout_metrics']['category_accuracy'] ?? 'n/a') ?></strong><small>real holdout</small></div>
                <div><span>Category macro F1</span><strong><?= e($holdoutView['holdout_metrics']['category_macro_f1'] ?? 'n/a') ?></strong><small>real holdout</small></div>
                <div><span>Unknown false acceptance</span><strong><?= e($holdoutView['holdout_metrics']['unknown_false_acceptance_rate'] ?? 'n/a') ?></strong><small>unknown evaluation</small></div>
            </div>
        <?php else: ?>
            <p class="warning-text">Insufficient real holdout data or evaluation is blocked. Synthetic rows are not accepted as real evidence.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="warning-text">Insufficient real holdout data. Add verified genuine receipt lines before using this evaluation path.</p>
    <?php endif; ?>
</div>

</section>

<section id="training-candidates" class="panel admin-workspace-panel">
    <div class="admin-section-head">
        <div>
            <h2>Training Candidate Review</h2>
            <p class="muted">User corrections remain quarantined until an administrator approves them. Rejected candidates are audited separately and never enter training.</p>
        </div>
        <strong><?= e(count($candidateRecords)) ?> pending</strong>
    </div>
    <?php if ($candidateRecords): ?>
        <div class="table-wrap"><table><thead><tr><th>Original line</th><th>Proposed label</th><th>Source</th><th>Variant group</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($candidateRecords as $candidateIndex => $candidate): ?>
            <tr>
                <td><?= e($candidate['receipt_line'] ?? '') ?></td>
                <td><?= e($candidate['label'] ?? '') ?></td>
                <td><?= e($candidate['source'] ?? 'user_feedback') ?></td>
                <td><?= e($candidate['variant_group'] ?? '') ?></td>
                <td><form method="post" class="inline-admin-action"><input type="hidden" name="ml_action" value="review_training_candidate"><input type="hidden" name="candidate_index" value="<?= e($candidateIndex) ?>"><?= csrf_field() ?><button class="button primary" name="decision" value="approve" type="submit">Approve</button><button class="button ghost" name="decision" value="reject" type="submit">Reject</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php else: ?>
        <p class="muted">No pending training candidates.</p>
    <?php endif; ?>
</section>

<section id="manage-users" class="panel">
    <h2>Manage User Accounts</h2>
    <p class="muted">Only signed-in admins can create admin accounts. Public registration always creates normal user accounts.</p>

    <form class="catalog-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="user_action" value="create_user">
        <div class="grid two">
            <label><span>Username</span><input type="text" name="name" placeholder="manager1" autocomplete="off" required></label>
            <label><span>Email</span><input type="email" name="email" placeholder="manager@example.com" autocomplete="off" required></label>
        </div>
        <div class="grid two">
            <label><span>Password</span><input type="password" name="password" minlength="6" placeholder="Minimum 6 characters" autocomplete="new-password" required></label>
            <label>
                <span>Account role</span>
                <select name="role" required>
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </label>
        </div>
        <button class="button primary" type="submit">Create account</button>
    </form>

    <?php if ($registeredUsers): ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Provider</th><th>Created</th></tr></thead>
                <tbody>
                    <?php foreach ($registeredUsers as $registeredUser): ?>
                        <tr>
                            <td><?= e($registeredUser['name'] ?? '') ?></td>
                            <td><?= e($registeredUser['email'] ?? '') ?></td>
                            <td><span class="risk-badge <?= ($registeredUser['role'] ?? '') === 'admin' ? 'risk-moderate' : 'risk-low' ?>"><?= e(ucfirst((string)($registeredUser['role'] ?? 'user'))) ?></span></td>
                            <td><?= e($registeredUser['auth_provider'] ?? 'local') ?></td>
                            <td><?= e(isset($registeredUser['created_at']) ? date('M d, Y H:i', strtotime((string)$registeredUser['created_at'])) : 'Unknown') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section id="manage-food-database" class="panel admin-workspace-panel">
    <div class="admin-section-head">
        <div>
            <h2>Food Catalog Records</h2>
            <p class="muted">This is the food rulebook used by receipt analysis: names, aliases, nutrients, risk labels, and healthier swaps.</p>
        </div>
        <a class="button ghost" href="api/export_catalog.php">Export CSV</a>
    </div>

    <div class="admin-summary-grid">
        <div><span>Total foods</span><strong><?= e(count($catalog)) ?></strong><small>available for matching</small></div>
        <div><span>Categories</span><strong><?= e(count($catalogCategories)) ?></strong><small><?= e(implode(', ', array_slice(array_keys($catalogTopCategories), 0, 3)) ?: 'none') ?></small></div>
        <div><span>Risk rules</span><strong><?= e(count($catalogRiskRules)) ?></strong><small>flagged as moderate or high</small></div>
        <div><span>Swap ideas</span><strong><?= e(count($catalogWithAlternatives)) ?></strong><small>foods with alternatives</small></div>
    </div>

    <div class="admin-tool-grid">
        <section class="admin-tool-box">
            <div class="admin-tool-head">
                <h3>Add or Update One Food</h3>
                <p class="muted">Use this for a single missing item or one quick nutrition correction.</p>
            </div>

            <form class="catalog-form" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="catalog_action" value="save">
                <div class="grid two">
                    <label><span>Food name</span><input type="text" name="name" placeholder="coconut water" required></label>
                    <label><span>Category</span><input type="text" name="category" placeholder="drink" required></label>
                </div>
                <div class="nutrient-input-grid">
                    <label><span>Sugar g</span><input type="number" name="sugar_g" min="0" step="0.1" value="0"></label>
                    <label><span>Sat fat g</span><input type="number" name="saturated_fat_g" min="0" step="0.1" value="0"></label>
                    <label><span>Sodium mg</span><input type="number" name="sodium_mg" min="0" step="0.1" value="0"></label>
                    <label><span>Fiber g</span><input type="number" name="fiber_g" min="0" step="0.1" value="0"></label>
                </div>
                <label><span>Risk label</span><input type="text" name="risk" placeholder="moderate natural sugar drink" value="low risk"></label>
                <label><span>Recommendation</span><textarea name="recommendation" rows="2" placeholder="Choose unsweetened coconut water in small portions."></textarea></label>
                <div class="grid two">
                    <label><span>Aliases</span><textarea name="aliases" rows="2" placeholder="coconut water, king coconut"></textarea></label>
                    <label><span>Healthier swaps</span><textarea name="alternatives" rows="2" placeholder="water, unsweetened tea"></textarea></label>
                </div>
                <button class="button primary" type="submit">Save food rule</button>
            </form>
        </section>

        <section class="admin-tool-box admin-bulk-tool">
            <div class="admin-tool-head">
                <h3>Bulk Update Catalog</h3>
                <p class="muted">Best for many records. Export, edit in a spreadsheet, then import the updated CSV.</p>
            </div>

            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="catalog_action" value="import_csv">
                <label class="upload-box compact-upload" data-empty-label="No CSV selected">
                    <span>Import updated CSV</span>
                    <input type="file" name="catalog_csv" accept=".csv" required>
                    <small>Required columns: name, category, sugar_g, saturated_fat_g, sodium_mg, fiber_g, risk, recommendation, aliases, alternatives</small>
                </label>
                <div class="form-actions">
                    <button class="button primary" type="submit">Import CSV</button>
                    <a class="button ghost" href="api/export_catalog.php">Download current CSV</a>
                </div>
            </form>

            <?php if ($catalogPreview): ?>
                <div class="catalog-preview-list" aria-label="Food catalog preview">
                    <?php foreach ($catalogPreview as $food): ?>
                        <div>
                            <strong><?= e($food['name'] ?? '') ?></strong>
                            <span><?= e($food['category'] ?? 'other') ?></span>
                            <small><?= e($food['risk'] ?? 'low risk') ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>

<details class="panel catalog-rules-panel" data-catalog-rules>
    <summary>
        <span>
            <strong>Optional: Edit Existing Food Rules</strong>
            <small>Use this only when one food item needs a quick fix. For many changes, export the CSV, edit it, then import it back.</small>
        </span>
    </summary>

    <div class="catalog-filter-bar">
        <label>
            <span>Search food rule</span>
            <input type="search" placeholder="Search by food, category, risk, alias..." data-catalog-filter>
        </label>
        <strong data-catalog-count><?= e(count($catalog)) ?> rules</strong>
    </div>

    <p class="muted">These rules power item matching, nutrition scoring, risk labels, and recommendation text. Bulk updates are easier through the CSV import/export controls above.</p>

    <div class="catalog-editor-grid">
        <?php foreach ($catalog as $food): ?>
            <?php
                $searchText = strtolower(implode(' ', [
                    (string)($food['name'] ?? ''),
                    (string)($food['category'] ?? ''),
                    (string)($food['risk'] ?? ''),
                    implode(' ', array_map('strval', $food['aliases'] ?? [])),
                    implode(' ', array_map('strval', $food['alternatives'] ?? [])),
                ]));
            ?>
            <form class="catalog-editor-card" method="post" data-catalog-card data-catalog-search="<?= e($searchText) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="original_name" value="<?= e($food['name'] ?? '') ?>">
                <div class="grid two">
                    <label><span>Food</span><input type="text" name="name" value="<?= e($food['name'] ?? '') ?>" required></label>
                    <label><span>Category</span><input type="text" name="category" value="<?= e($food['category'] ?? '') ?>" required></label>
                </div>
                <div class="nutrient-input-grid">
                    <label><span>Sugar</span><input type="number" name="sugar_g" min="0" step="0.1" value="<?= e($food['sugar_g'] ?? 0) ?>"></label>
                    <label><span>Sat fat</span><input type="number" name="saturated_fat_g" min="0" step="0.1" value="<?= e($food['saturated_fat_g'] ?? 0) ?>"></label>
                    <label><span>Sodium</span><input type="number" name="sodium_mg" min="0" step="0.1" value="<?= e($food['sodium_mg'] ?? 0) ?>"></label>
                    <label><span>Fiber</span><input type="number" name="fiber_g" min="0" step="0.1" value="<?= e($food['fiber_g'] ?? 0) ?>"></label>
                </div>
                <label><span>Risk</span><input type="text" name="risk" value="<?= e($food['risk'] ?? '') ?>"></label>
                <label><span>Recommendation</span><textarea name="recommendation" rows="2"><?= e($food['recommendation'] ?? '') ?></textarea></label>
                <div class="grid two">
                    <label><span>Aliases</span><textarea name="aliases" rows="2"><?= e(implode(', ', array_map('strval', $food['aliases'] ?? [$food['name'] ?? '']))) ?></textarea></label>
                    <label><span>Alternatives</span><textarea name="alternatives" rows="2"><?= e(implode(', ', array_map('strval', $food['alternatives'] ?? []))) ?></textarea></label>
                </div>
                <div class="form-actions">
                    <button class="button primary" type="submit" name="catalog_action" value="save">Save</button>
                    <button class="button ghost danger-action" type="submit" name="catalog_action" value="delete">Delete</button>
                </div>
            </form>
        <?php endforeach; ?>
    </div>
    <p class="muted catalog-no-results" data-catalog-empty hidden>No matching food rule found.</p>
</details>

<?php if ($holdoutReviewResult): ?>
    <?php
        $holdoutCatalog = holdout_catalog_by_label();
        $holdoutReviewAsset = is_array($holdoutReviewResult['receipt_asset'] ?? null) ? $holdoutReviewResult['receipt_asset'] : [];
        $holdoutReviewCorrectionAsset = is_array($holdoutReviewResult['correction_context']['original_asset'] ?? null) ? $holdoutReviewResult['correction_context']['original_asset'] : [];
        $holdoutReviewMetadata = is_array($holdoutReviewResult['receipt_metadata'] ?? null) ? $holdoutReviewResult['receipt_metadata'] : [];
        $holdoutReviewOcr = is_array($holdoutReviewResult['ocr_status'] ?? null) ? $holdoutReviewResult['ocr_status'] : [];
    ?>
    <section id="holdout-review" class="panel admin-workspace-panel">
        <div class="admin-section-head">
            <div>
                <h2>Verify receipt lines</h2>
                <p class="muted">Review each detected line before promoting it to the evaluation-only holdout. Nothing is saved until you explicitly confirm it.</p>
            </div>
            <span class="risk-badge risk-moderate">Evaluation only — never used for training</span>
        </div>
        <div class="admin-summary-grid">
            <div><span>Receipt ID</span><strong><?= e($holdoutReviewResult['receipt_id'] ?? $holdoutReviewId) ?></strong><small>source report</small></div>
            <div><span>Receipt date</span><strong><?= e($holdoutReviewMetadata['receipt_date'] ?? 'Not available') ?></strong><small>receipt metadata</small></div>
            <div><span>Store</span><strong><?= e($holdoutReviewMetadata['store_name'] ?? 'Not available') ?></strong><small>receipt metadata</small></div>
            <div><span>OCR confidence</span><strong><?= e($holdoutReviewOcr['confidence'] ?? 'Not available') ?></strong><small><?= e($holdoutReviewResult['source_type'] ?? 'receipt source') ?></small></div>
        </div>
        <?php if (empty($holdoutReviewAsset['original_name']) && empty($holdoutReviewCorrectionAsset['original_name'])): ?>
            <p class="warning-text">This is a JSON-only historical report without preserved upload metadata. Promote lines only after independently confirming the receipt evidence.</p>
        <?php endif; ?>
        <?php if ($holdoutReviewConflicts): ?>
            <div class="warning-text">Contamination detected. Exclude the flagged lines before saving.</div>
            <details class="admin-technical-list" open>
                <summary>Contamination details</summary>
                <?php foreach ($holdoutReviewConflicts as $conflict): ?>
                    <p class="muted"><?= e($conflict['receipt_line'] ?? '') ?> overlaps <?= e($conflict['existing_line'] ?? '') ?> from <?= e($conflict['source'] ?? '') ?><?= !empty($conflict['conflicting_label']) ? ' with a conflicting label.' : '.' ?></p>
                <?php endforeach; ?>
            </details>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="holdout_action" value="save_verified_holdout">
            <input type="hidden" name="receipt_id" value="<?= e($holdoutReviewId) ?>">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Keep</th><th>Original detected line</th><th>Verified receipt text</th><th>Canonical label</th><th>Category</th><th>Quantity</th><th>Detection</th><th>Confidence</th></tr></thead>
                    <tbody>
                    <?php foreach (($holdoutReviewResult['items'] ?? []) as $itemIndex => $item): ?>
                        <?php
                            if (!is_array($item)) {
                                continue;
                            }
                            $itemLabel = normalize_training_feedback_text((string)($item['name'] ?? ''));
                            $itemCategory = (string)($item['category'] ?? '');
                        ?>
                        <tr>
                            <td><input type="checkbox" name="holdout_include[]" value="<?= e($itemIndex) ?>" checked aria-label="Keep line <?= e($itemIndex + 1) ?>"></td>
                            <td><?= e($item['raw_line'] ?? $item['name'] ?? '') ?></td>
                            <td><input type="text" name="holdout_line[<?= e($itemIndex) ?>]" value="<?= e($item['training_receipt_line'] ?? $item['raw_line'] ?? $item['name'] ?? '') ?>" required></td>
                            <td>
                                <select name="holdout_label[<?= e($itemIndex) ?>]" required>
                                    <option value="">Choose label</option>
                                    <?php foreach ($holdoutCatalog as $label => $food): ?>
                                        <option value="<?= e($label) ?>" <?= $label === $itemLabel ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><?= e($itemCategory ?: 'Review from catalog') ?><input type="hidden" name="holdout_category[<?= e($itemIndex) ?>]" value="<?= e($itemCategory) ?>"></td>
                            <td><?= e($item['quantity'] ?? 1) ?></td>
                            <td><?= e($item['detection_method'] ?? 'reviewed') ?></td>
                            <td><?= e($item['confidence'] ?? 'Not available') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions">
                <button class="button primary" type="submit">Save Verified Holdout Rows</button>
                <a class="button ghost" href="admin.php#generated-results">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<section id="generated-results" class="panel admin-workspace-panel">
    <div class="admin-section-head">
        <div>
            <h2>Admin Reports</h2>
            <p class="muted">Review recent receipt analyses, open the user-facing dashboard, or download a clean PDF report.</p>
        </div>
        <a class="button ghost" href="reports.php">Open reports page</a>
    </div>

    <div class="admin-summary-grid">
        <div><span>Total reports</span><strong><?= e(count($results)) ?></strong><small>receipt analyses saved</small></div>
        <div><span>Average score</span><strong><?= e($averageReportScore) ?></strong><small>across saved reports</small></div>
        <div><span>Needs attention</span><strong><?= e(count($lowScoreReports)) ?></strong><small>scores below 65</small></div>
        <div><span>Database linked</span><strong><?= e(count($databaseLinkedReports)) ?></strong><small><?= $latestReport ? 'latest ' . e(date('M d, H:i', (int)$latestReport['_created_at'])) : 'no reports yet' ?></small></div>
        <div><span>Holdout eligible</span><strong><?= e(count($eligibleHoldoutReports)) ?></strong><small><?= e(count($promotedHoldoutReportIds)) ?> already promoted</small></div>
    </div>

    <?php if (!$recentResults): ?>
        <div class="admin-empty-state">
            <strong>No reports yet</strong>
            <span>Upload and analyze a receipt to create the first admin report.</span>
            <a class="button primary" href="index.php">Analyze receipt</a>
        </div>
    <?php else: ?>
        <div class="admin-report-grid">
            <?php foreach ($recentResults as $result): ?>
                <?php
                    $reportScore = score_value($result);
                    $reportLabel = (string)($result['health_score']['label'] ?? 'Unknown');
                    $reportDate = date('M d, Y H:i', (int)$result['_created_at']);
                    $priorityCount = is_array($result['priority_alerts'] ?? null) ? count($result['priority_alerts']) : 0;
                    $signalCount = $priorityCount + anomaly_count($result);
                    $databaseLabel = !empty($result['database_receipt_id']) ? 'Saved in database' : 'JSON file only';
                    $holdoutEligible = holdout_report_is_eligible($result);
                    $holdoutAlreadyAdded = holdout_receipt_already_added((string)($result['receipt_id'] ?? ''));
                ?>
                <article class="admin-report-card <?= e(health_score_class($reportScore)) ?>">
                    <div class="admin-report-main">
                        <span><?= e($reportDate) ?></span>
                        <strong><?= e($reportScore) ?></strong>
                        <small><?= e($reportLabel) ?></small>
                    </div>
                    <dl>
                        <div><dt>Items</dt><dd><?= e(result_item_count($result)) ?></dd></div>
                        <div><dt>Alerts</dt><dd><?= e($signalCount) ?></dd></div>
                        <div><dt>Status</dt><dd><?= e($databaseLabel) ?></dd></div>
                        <div><dt>Holdout</dt><dd><?= $holdoutAlreadyAdded ? 'Already added' : ($holdoutEligible ? 'Eligible for review' : 'Unavailable') ?></dd></div>
                    </dl>
                    <div class="form-actions">
                        <a class="button primary" href="dashboard.php?id=<?= e($result['_id']) ?>">Open</a>
                        <a class="button ghost" href="api/export_report.php?format=pdf&id=<?= e($result['_id']) ?>">PDF</a>
                        <?php if ($holdoutAlreadyAdded): ?>
                            <span class="button ghost" aria-label="Already added to holdout">Already added to holdout</span>
                        <?php elseif ($holdoutEligible): ?>
                            <a class="button ghost" href="admin.php?holdout_review=<?= e($result['_id']) ?>#holdout-review">Add to Holdout</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <details class="admin-technical-list">
            <summary>Show technical report file list</summary>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Report ID</th><th>Date</th><th>Score</th><th>Database ID</th><th>Open</th></tr></thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr>
                                <td><?= e($result['_id']) ?></td>
                                <td><?= e(date('M d, Y H:i', $result['_created_at'])) ?></td>
                                <td><?= e(score_value($result)) ?></td>
                                <td><?= e($result['database_receipt_id'] ?? 'JSON only') ?></td>
                                <td><a class="table-link" href="dashboard.php?id=<?= e($result['_id']) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>
</section>

<?php render_page_end(); ?>
