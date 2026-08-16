<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';
require_once __DIR__ . '/includes/catalog.php';

function ml_metrics_path(): string
{
    return ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'food_classifier_metrics.json';
}

function ml_model_info_path(): string
{
    return ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'food_classifier_model.json';
}

function load_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
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
        throw new RuntimeException('Dataset upload failed.');
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

    if (!move_uploaded_file((string)$file['tmp_name'], $datasetPath)) {
        throw new RuntimeException('Could not save uploaded dataset.');
    }

    return run_food_model_training($datasetPath);
}

function train_current_feedback_dataset(): array
{
    return run_food_model_training(DATA_DIR . DIRECTORY_SEPARATOR . 'training_food_items.csv');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['catalog_action'] ?? $_POST['user_action'] ?? $_POST['ml_action'] ?? '');

    try {
        if (!is_valid_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token expired. Please refresh the page and try again.');
        }

        if ($action === 'create_user') {
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
        }
    } catch (Throwable $exception) {
        $adminError = $exception->getMessage();
    }
}

$counts = database_counts();
$results = load_all_results();
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
    '<a class="button primary" href="#manage-users">Manage users</a><a class="button ghost" href="#manage-food-database">Food database</a><a class="button ghost" href="setup_check.php">Run setup check</a>'
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
    <article class="metric"><span>Python</span><strong><?= e(PYTHON_COMMAND) ?></strong><small>analysis command</small></article>
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
        </div>
    </div>

    <div class="admin-summary-grid">
        <div><span>Item accuracy</span><strong><?= e($mlMetrics['accuracy'] ?? 'n/a') ?></strong><small>latest test split</small></div>
        <div><span>Category accuracy</span><strong><?= e($mlMetrics['category_accuracy'] ?? 'n/a') ?></strong><small>fallback classifier</small></div>
        <div><span>Labels</span><strong><?= e(is_array($mlMetrics['labels'] ?? null) ? count($mlMetrics['labels']) : 0) ?></strong><small>food classes</small></div>
        <div><span>Model version</span><strong><?= e($mlModelInfo['version'] ?? 'n/a') ?></strong><small><?= e($mlModelInfo['trained_at'] ?? 'not trained') ?></small></div>
    </div>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="ml_action" value="train_ml_dataset">
        <label class="upload-box compact-upload" data-empty-label="No dataset selected">
            <span>Upload training CSV</span>
            <input type="file" name="ml_dataset_csv" accept=".csv" required>
            <small>Required: receipt_line, label. Optional: category, sugar_g, saturated_fat_g, sodium_mg, fiber_g, risk, recommendation, aliases, alternatives.</small>
        </label>
        <button class="button primary" type="submit">Validate and train ML model</button>
    </form>

    <form method="post" class="inline-admin-action">
        <?= csrf_field() ?>
        <input type="hidden" name="ml_action" value="retrain_current_feedback">
        <button class="button ghost" type="submit">Retrain model from current feedback dataset</button>
    </form>

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
                    </dl>
                    <div class="form-actions">
                        <a class="button primary" href="dashboard.php?id=<?= e($result['_id']) ?>">Open</a>
                        <a class="button ghost" href="api/export_report.php?format=pdf&id=<?= e($result['_id']) ?>">PDF</a>
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
