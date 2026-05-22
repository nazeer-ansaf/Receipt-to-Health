<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';

$result = load_result($_GET['id'] ?? 'latest');
$results = load_all_results();

render_page_start('Reports', 'reports');
page_hero(
    'Presentation output',
    'Household Nutrition Report Generator',
    'A printable report view for demonstrations, viva explanations, and final-year documentation.',
    '<button class="button primary" onclick="window.print()">Print report</button>'
);
?>

<?php if (!$result): ?>
    <section class="panel"><h2>No report available</h2><p>Analyze a receipt first.</p></section>
<?php else: ?>
    <?php $score = score_value($result); ?>

    <section class="report-sheet">
        <div class="report-header">
            <div>
                <p class="eyebrow">Generated household report</p>
                <h1><?= e(APP_NAME) ?></h1>
                <p><?= e(date('F d, Y H:i', $result['_created_at'])) ?></p>
            </div>
            <div class="report-score <?= e(health_score_class($score)) ?>">
                <span>Score</span>
                <strong><?= e($score) ?></strong>
                <small><?= e($result['health_score']['label'] ?? 'Unknown') ?></small>
            </div>
        </div>

        <div class="report-actions">
            <a class="button ghost" href="api/export_report.php?format=json&id=<?= e($result['_id']) ?>">Export JSON</a>
            <a class="button ghost" href="api/export_report.php?format=csv&id=<?= e($result['_id']) ?>">Export CSV</a>
            <a class="button ghost" href="dashboard.php?id=<?= e($result['_id']) ?>">Open dashboard</a>
        </div>

        <section class="grid two">
            <article>
                <h2>Family Context</h2>
                <dl class="facts compact">
                    <div><dt>Family size</dt><dd><?= e($result['family']['family_size'] ?? '-') ?></dd></div>
                    <div><dt>Age group</dt><dd><?= e($result['family']['age_group'] ?? '-') ?></dd></div>
                    <div><dt>Conditions</dt><dd><?= e(condition_text($result['family'] ?? [])) ?></dd></div>
                    <div><dt>Database ID</dt><dd><?= e($result['database_receipt_id'] ?? 'JSON only') ?></dd></div>
                </dl>
            </article>

            <article>
                <h2>Per-Person Nutrition</h2>
                <dl class="facts compact">
                    <?php foreach (($result['per_person_nutrition'] ?? []) as $key => $value): ?>
                        <div><dt><?= e(ucwords(str_replace('_', ' ', $key))) ?></dt><dd><?= e($value) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </article>
        </section>

        <section>
            <h2>Score Breakdown</h2>
            <div class="score-grid">
                <?php foreach (($result['health_score']['breakdown'] ?? []) as $key => $value): ?>
                    <div class="score-chip"><span><?= e(ucwords($key)) ?></span><strong><?= e($value) ?></strong></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="grid two">
            <article>
                <h2>Anomalies</h2>
                <ul class="insight-list">
                    <?php foreach (($result['anomalies'] ?? []) as $anomaly): ?>
                        <li><strong><?= e($anomaly['item'] ?? '') ?></strong><span><?= e($anomaly['message'] ?? '') ?></span></li>
                    <?php endforeach; ?>
                    <?php if (empty($result['anomalies'])): ?><li>No anomaly flags.</li><?php endif; ?>
                </ul>
            </article>

            <article>
                <h2>Recommendations</h2>
                <ul class="insight-list">
                    <?php foreach (($result['recommendations'] ?? []) as $recommendation): ?>
                        <li><?= e($recommendation) ?></li>
                    <?php endforeach; ?>
                </ul>
            </article>
        </section>

        <section>
            <h2>Normalized Item Evidence</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Item</th><th>Quantity</th><th>Category</th><th>Risk</th><th>Confidence</th></tr></thead>
                    <tbody>
                        <?php foreach (($result['items'] ?? []) as $item): ?>
                            <tr>
                                <td><?= e($item['name'] ?? '') ?></td>
                                <td><?= e($item['quantity'] ?? '') ?></td>
                                <td><?= e($item['category'] ?? '') ?></td>
                                <td><?= e($item['risk'] ?? '') ?></td>
                                <td><?= e($item['confidence'] ?? 'n/a') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
<?php endif; ?>

<?php render_page_end(); ?>

