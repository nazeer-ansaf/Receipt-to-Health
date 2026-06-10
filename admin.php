<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';
require_once __DIR__ . '/includes/catalog.php';

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
    $action = (string)($_POST['catalog_action'] ?? $_POST['user_action'] ?? '');

    try {
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
        }
    } catch (Throwable $exception) {
        $adminError = $exception->getMessage();
    }
}

$counts = database_counts();
$results = load_all_results();
$catalog = food_catalog();
$registeredUsers = [];

try {
    $registeredUsers = list_registered_users();
} catch (Throwable $exception) {
    $adminError = $adminError !== '' ? $adminError : 'User list unavailable: ' . $exception->getMessage();
}

render_page_start('Admin Evidence', 'admin');
page_hero(
    'System evidence',
    'Admin and Data Integrity Console',
    'This page is useful for final demonstrations because it proves that the project has backend storage, AI outputs, catalog data, and system readiness checks.',
    '<a class="button ghost" href="setup_check.php">Run setup check</a>'
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
        </div>
    </article>
</section>

<section class="panel">
    <h2>Manage User Accounts</h2>
    <p class="muted">Only signed-in admins can create admin accounts. Public registration always creates normal user accounts.</p>

    <form class="catalog-form" method="post">
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

<section class="panel">
    <h2>Manage Food Database</h2>

    <div class="admin-import-export">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="catalog_action" value="import_csv">
            <label class="upload-box compact-upload" data-empty-label="No CSV selected">
                <span>Import food catalog CSV</span>
                <input type="file" name="catalog_csv" accept=".csv" required>
                <small>Columns: name, category, sugar_g, saturated_fat_g, sodium_mg, fiber_g, risk, recommendation, aliases, alternatives</small>
            </label>
            <button class="button primary" type="submit">Import CSV</button>
        </form>
        <a class="button ghost" href="api/export_catalog.php">Export food database CSV</a>
    </div>

    <form class="catalog-form" method="post">
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
        <label><span>Recommendation rule</span><textarea name="recommendation" rows="2" placeholder="Choose unsweetened coconut water in small portions."></textarea></label>
        <div class="grid two">
            <label><span>Aliases</span><textarea name="aliases" rows="2" placeholder="coconut water, king coconut"></textarea></label>
            <label><span>Alternatives</span><textarea name="alternatives" rows="2" placeholder="water, unsweetened tea"></textarea></label>
        </div>
        <button class="button primary" type="submit">Add food item</button>
    </form>
</section>

<section class="panel">
    <h2>Edit Existing Catalog Rules</h2>
    <div class="catalog-editor-grid">
        <?php foreach ($catalog as $food): ?>
            <form class="catalog-editor-card" method="post">
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
</section>

<section class="panel">
    <h2>Generated Result Files</h2>
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
</section>

<?php render_page_end(); ?>
