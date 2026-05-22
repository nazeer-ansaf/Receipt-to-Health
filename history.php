<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';

$results = load_all_results();

render_page_start('Receipt History', 'history');
page_hero(
    'Long-term monitoring',
    'Receipt History and Trend Memory',
    'Every analyzed receipt becomes a data point for household nutrition trends, anomaly baselines, and report comparison.',
    '<a class="button primary" href="index.php">Upload receipt</a>'
);
?>

<section class="score-band">
    <article class="metric">
        <span>Total reports</span>
        <strong><?= count($results) ?></strong>
        <small>stored analyses</small>
    </article>
    <article class="metric">
        <span>Average score</span>
        <strong><?= e(average_score($results)) ?></strong>
        <small>across history</small>
    </article>
    <article class="metric">
        <span>Total items</span>
        <strong><?= e(array_sum(array_map('result_item_count', $results))) ?></strong>
        <small>normalized purchases</small>
    </article>
    <article class="metric">
        <span>Anomaly flags</span>
        <strong><?= e(array_sum(array_map('anomaly_count', $results))) ?></strong>
        <small>Z-score alerts</small>
    </article>
</section>

<section class="panel">
    <h2>Score Timeline</h2>
    <?php if (count($results) < 2): ?>
        <p class="muted">Analyze more receipts to create a stronger timeline.</p>
    <?php else: ?>
        <div class="trend-strip large">
            <?php foreach (array_reverse($results) as $result): ?>
                <?php $score = score_value($result); ?>
                <a href="dashboard.php?id=<?= e($result['_id']) ?>" title="<?= e(date('M d, Y H:i', $result['_created_at'])) ?>">
                    <i class="<?= e(health_score_class($score)) ?>" style="height: <?= e((string)max(8, min(100, $score))) ?>%"></i>
                    <span><?= e($score) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Report Archive</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Score</th>
                    <th>Label</th>
                    <th>Family</th>
                    <th>Items</th>
                    <th>Anomalies</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $result): ?>
                    <?php $score = score_value($result); ?>
                    <tr>
                        <td><?= e(date('M d, Y H:i', $result['_created_at'])) ?></td>
                        <td><strong class="<?= e(health_score_class($score)) ?>-text"><?= e($score) ?></strong></td>
                        <td><?= e($result['health_score']['label'] ?? 'Unknown') ?></td>
                        <td><?= e($result['family']['family_size'] ?? '-') ?> members</td>
                        <td><?= result_item_count($result) ?></td>
                        <td><?= anomaly_count($result) ?></td>
                        <td><a class="table-link" href="dashboard.php?id=<?= e($result['_id']) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$results): ?>
                    <tr><td colspan="7">No receipt reports have been created yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_page_end(); ?>

