<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';

$results = load_all_results();
$nutrients = aggregate_nutrients($results);
$categories = aggregate_categories($results);
$items = aggregate_items($results);
$recommendations = recommendation_frequency($results);
$moving = moving_average_scores($results);
$latest = $results[0] ?? null;

render_page_start('Analytics Center', 'analytics');
page_hero(
    'Statistical intelligence',
    'Household Nutrition Analytics Center',
    'This module turns uploaded receipts into longitudinal analytics: moving averages, category dominance, nutrient pressure, risky item frequency, and recommendation recurrence.',
    '<a class="button primary" href="reports.php">Generate report</a>'
);
?>

<section class="score-band">
    <article class="metric">
        <span>Reports analyzed</span>
        <strong><?= count($results) ?></strong>
        <small>history points</small>
    </article>
    <article class="metric <?= e(health_score_class(average_score($results))) ?>">
        <span>Average score</span>
        <strong><?= e(average_score($results)) ?></strong>
        <small>moving health level</small>
    </article>
    <article class="metric">
        <span>Top category</span>
        <strong><?= e($categories ? ucwords((string)array_key_first($categories)) : '-') ?></strong>
        <small>by quantity</small>
    </article>
    <article class="metric">
        <span>Repeated advice</span>
        <strong><?= count($recommendations) ?></strong>
        <small>recommendation types</small>
    </article>
</section>

<section class="grid two">
    <article class="panel">
        <h2>Nutrient Pressure Averages</h2>
        <div class="risk-matrix">
            <?php foreach (nutrient_rows($nutrients) as $row): ?>
                <div class="risk-row">
                    <div>
                        <strong><?= e($row['label']) ?></strong>
                        <span><?= e($row['status']) ?>, target <?= e($row['target']) ?> <?= e($row['unit']) ?></span>
                    </div>
                    <div class="bar-track"><i style="width: <?= e((string)$row['percent']) ?>%"></i></div>
                    <b><?= e($row['value']) ?> <?= e($row['unit']) ?></b>
                </div>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="panel">
        <h2>Category Dominance</h2>
        <div class="category-grid">
            <?php foreach (array_slice($categories, 0, 8, true) as $category => $quantity): ?>
                <div>
                    <span><?= e(ucwords($category)) ?></span>
                    <strong><?= e($quantity) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<section class="panel">
    <h2>Moving Average Score Timeline</h2>
    <?php if (count($moving) < 2): ?>
        <p class="muted">Analyze more receipts to create a visible moving average.</p>
    <?php else: ?>
        <div class="analytics-timeline">
            <?php foreach ($moving as $point): ?>
                <a href="dashboard.php?id=<?= e($point['id']) ?>">
                    <i style="height: <?= e((string)max(8, min(100, $point['score']))) ?>%"></i>
                    <b><?= e($point['score']) ?></b>
                    <span>MA <?= e($point['moving_average']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="grid two">
    <article class="panel">
        <h2>Top Purchased Items</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Item</th><th>Total qty</th><th>Receipts</th><th>Risk</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($items, 0, 12, true) as $name => $item): ?>
                        <tr>
                            <td><?= e($name) ?></td>
                            <td><?= e($item['quantity']) ?></td>
                            <td><?= e($item['count']) ?></td>
                            <td><?= e($item['risk']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel">
        <h2>Recurring Recommendations</h2>
        <ul class="insight-list">
            <?php foreach (array_slice($recommendations, 0, 8, true) as $recommendation => $count): ?>
                <li>
                    <strong><?= e($count) ?>x</strong>
                    <span><?= e($recommendation) ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (!$recommendations): ?>
                <li>No recommendation history yet.</li>
            <?php endif; ?>
        </ul>
    </article>
</section>

<section class="panel">
    <h2>Latest Risk Summary</h2>
    <?php if (!$latest): ?>
        <p>No latest result available.</p>
    <?php else: ?>
        <div class="risk-cards">
            <?php foreach (($latest['risk_summary'] ?? []) as $key => $value): ?>
                <div>
                    <strong><?= e(ucwords(str_replace('_', ' ', $key))) ?></strong>
                    <span><?= e(is_array($value) ? implode(', ', $value) : (string)$value) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php render_page_end(); ?>

