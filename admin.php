<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';
require_once __DIR__ . '/includes/catalog.php';

$counts = database_counts();
$results = load_all_results();
$catalog = food_catalog();

render_page_start('Admin Evidence', 'admin');
page_hero(
    'System evidence',
    'Admin and Data Integrity Console',
    'This page is useful for final demonstrations because it proves that the project has backend storage, AI outputs, catalog data, and system readiness checks.',
    '<a class="button ghost" href="setup_check.php">Run setup check</a>'
);
?>

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
            <div><b>Done</b><span>Register/login/logout and user-linked receipts</span></div>
            <div><b>Next</b><span>Real image OCR with Tesseract/EasyOCR</span></div>
        </div>
    </article>
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
