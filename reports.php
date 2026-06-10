<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';

$result = load_result($_GET['id'] ?? 'latest');
$results = load_all_results();

render_page_start('Reports', 'reports');
page_hero(
    'Share result',
    'Readable Nutrition Report',
    'Use this page when you want a clean version of the result for printing, exporting, or demo submission.',
    '<button class="button primary" onclick="window.print()">Print report</button>'
);
?>

<?php if (!$result): ?>
    <section class="panel"><h2>No report available</h2><p>Analyze a receipt first.</p></section>
<?php else: ?>
    <?php
        $score = score_value($result);
        $recommendationCards = recommendation_cards($result);
        $scoreExplanation = score_explanation($result);
    ?>

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
            <a class="button ghost" href="api/export_report.php?format=pdf&id=<?= e($result['_id']) ?>">Export PDF</a>
            <a class="button ghost" href="api/export_report.php?format=json&id=<?= e($result['_id']) ?>">Export JSON</a>
            <a class="button ghost" href="api/export_report.php?format=csv&id=<?= e($result['_id']) ?>">Export CSV</a>
            <a class="button ghost" href="dashboard.php?id=<?= e($result['_id']) ?>">Open dashboard</a>
        </div>

        <section class="report-help-strip">
            <strong>Best reading order</strong>
            <span>Score explanation first, priority alerts second, proof details only when needed.</span>
        </section>

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
            <h2>Plain-Language Score Explanation</h2>
            <p><?= e($scoreExplanation['summary'] ?? '') ?></p>
            <ul class="insight-list">
                <?php foreach (($scoreExplanation['reasons'] ?? []) as $reason): ?>
                    <li><?= e($reason) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section>
            <h2>Priority Alerts</h2>
            <div class="priority-alert-list">
                <?php foreach (($result['priority_alerts'] ?? []) as $alert): ?>
                    <article class="priority-alert <?= e(priority_class((string)($alert['priority'] ?? ''))) ?>">
                        <span><?= e($alert['priority'] ?? 'Watch') ?></span>
                        <strong><?= e($alert['title'] ?? '') ?></strong>
                        <p><?= e($alert['detail'] ?? '') ?></p>
                        <small><?= e($alert['proof'] ?? '') ?></small>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($result['priority_alerts'])): ?><p>No priority alerts stored.</p><?php endif; ?>
            </div>
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
                <h2>Recommendations With Proof</h2>
                <div class="recommendation-proof-list compact-proof-list">
                    <?php foreach ($recommendationCards as $card): ?>
                        <article class="recommendation-proof-card">
                            <div class="recommendation-proof-head">
                                <strong><?= e($card['advice']) ?></strong>
                                <span><?= e($card['trigger']) ?></span>
                            </div>
                            <?php if (!empty($card['proof_points'])): ?>
                                <div class="proof-stack">
                                    <b>Proof</b>
                                    <?php foreach ($card['proof_points'] as $proof): ?>
                                        <span><?= e($proof) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($card['evidence_items'])): ?>
                                <div class="proof-stack evidence-stack">
                                    <b>Receipt evidence</b>
                                    <?php foreach ($card['evidence_items'] as $evidence): ?>
                                        <span><?= e($evidence) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
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
