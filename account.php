<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';

$user = current_user();
$databaseStats = [];

if ($user) {
    try {
        $pdo = db();
        $receiptStatement = $pdo->prepare('SELECT COUNT(*) FROM receipts WHERE user_id = :user_id');
        $receiptStatement->execute([':user_id' => $user['id']]);
        $scoreStatement = $pdo->prepare(
            'SELECT AVG(hs.score) FROM health_scores hs INNER JOIN receipts r ON r.id = hs.receipt_id WHERE r.user_id = :user_id'
        );
        $scoreStatement->execute([':user_id' => $user['id']]);
        $databaseStats = [
            'receipts' => (int)$receiptStatement->fetchColumn(),
            'average_score' => round((float)$scoreStatement->fetchColumn(), 1),
        ];
    } catch (Throwable $exception) {
        $databaseStats = ['error' => $exception->getMessage()];
    }
}

render_page_start('Account', 'account');
page_hero(
    'User management',
    'Household Account',
    'This module demonstrates registration, login, password hashing, session handling, and user-linked receipt persistence.',
    $user ? '<a class="button ghost" href="logout.php">Logout</a>' : '<a class="button primary" href="login.php">Login</a>'
);
?>

<?php if (!$user): ?>
    <section class="grid two">
        <article class="panel">
            <h2>No user logged in</h2>
            <p class="muted">The app still works as a public demo, but logged-in receipt analyses are attached to a user record.</p>
            <a class="button primary" href="login.php">Login</a>
            <a class="button ghost" href="register.php">Register</a>
        </article>
        <article class="panel">
            <h2>Authentication Evidence</h2>
            <div class="module-list">
                <div><strong>Password hashing</strong><span>Uses PHP password_hash and password_verify.</span></div>
                <div><strong>Sessions</strong><span>Stores minimal account details in PHP session.</span></div>
                <div><strong>Database relation</strong><span>New receipts use users.id as receipts.user_id when logged in.</span></div>
            </div>
        </article>
    </section>
<?php else: ?>
    <section class="score-band">
        <article class="metric">
            <span>User</span>
            <strong><?= e($user['name']) ?></strong>
            <small><?= e($user['email']) ?></small>
        </article>
        <article class="metric">
            <span>Linked receipts</span>
            <strong><?= e($databaseStats['receipts'] ?? 0) ?></strong>
            <small>MySQL user records</small>
        </article>
        <article class="metric">
            <span>Average score</span>
            <strong><?= e($databaseStats['average_score'] ?? 0) ?></strong>
            <small>for this user</small>
        </article>
        <article class="metric">
            <span>Session</span>
            <strong>Active</strong>
            <small>local PHP session</small>
        </article>
    </section>

    <?php if (isset($databaseStats['error'])): ?>
        <section class="panel"><p class="warning-text"><?= e($databaseStats['error']) ?></p></section>
    <?php endif; ?>

    <section class="grid two">
        <article class="panel">
            <h2>Next User Actions</h2>
            <div class="module-list">
                <div><strong>Upload receipt</strong><span>New analysis will be linked to this account.</span></div>
                <div><strong>Update family profile</strong><span>Use household context for personalization.</span></div>
                <div><strong>Export reports</strong><span>Generate JSON/CSV evidence for this project.</span></div>
            </div>
        </article>
        <article class="panel">
            <h2>Security Features</h2>
            <div class="module-list">
                <div><strong>Hashed password</strong><span>Plain passwords are not stored.</span></div>
                <div><strong>Minimal session</strong><span>Only id, name, and email are kept in session.</span></div>
                <div><strong>Graceful demo mode</strong><span>Public analysis still works if no user is logged in.</span></div>
            </div>
        </article>
    </section>
<?php endif; ?>

<?php render_page_end(); ?>

