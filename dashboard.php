<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';

$resultId = $_GET['id'] ?? 'latest';
$result = load_result($resultId);
$allResults = load_all_results();
$history = array_reverse(array_slice($allResults, 0, 8));

render_page_start('Dashboard', 'dashboard');
page_hero(
    'AI nutrition dashboard',
    'Household Health Dashboard',
    'A full report showing normalized receipt items, scoring model evidence, trend context, anomaly flags, and recommendation reasoning.',
    '<a class="button primary" href="index.php">Analyze another receipt</a>'
);
?>

<?php if (!$result): ?>
    <section class="panel">
        <h2>No report yet</h2>
        <p>Upload a receipt first to generate household nutrition analysis.</p>
    </section>
<?php else: ?>
    <?php
        $score = score_value($result);
        $family = $result['family'] ?? [];
        $items = $result['items'] ?? [];
        $perPerson = $result['per_person_nutrition'] ?? [];
        $categories = category_distribution($items);
        $recommendations = $result['recommendations'] ?? [];
        $anomalies = $result['anomalies'] ?? [];
        $profileContext = $result['profile_context'] ?? [];
        $profileAnalysis = $result['profile_analysis'] ?? [];
    ?>

    <section class="score-band">
        <article class="metric <?= e(health_score_class($score)) ?>">
            <span>Health score</span>
            <strong><?= e($score) ?></strong>
            <small><?= e($result['health_score']['label'] ?? 'Unknown') ?></small>
        </article>
        <article class="metric">
            <span>Family size</span>
            <strong><?= e($family['family_size'] ?? 0) ?></strong>
            <small><?= e($family['age_group'] ?? 'not set') ?></small>
        </article>
        <article class="metric">
            <span>Receipts analyzed</span>
            <strong><?= count($allResults) ?></strong>
            <small>stored reports</small>
        </article>
        <article class="metric">
            <span>Risk alerts</span>
            <strong><?= count($anomalies) + count($recommendations) ?></strong>
            <small>signals found</small>
        </article>
    </section>

    <section class="panel">
        <h2>Seven-Layer AI Pipeline Evidence</h2>
        <div class="pipeline">
            <div><strong>OCR</strong><span><?= trim((string)($result['extracted_text'] ?? '')) !== '' ? 'Text extracted' : 'Waiting for OCR text' ?></span></div>
            <div><strong>NLP</strong><span><?= count($items) ?> items normalized</span></div>
            <div><strong>Knowledge Graph</strong><span><?= count($categories) ?> food categories mapped</span></div>
            <div><strong>Scoring</strong><span><?= e($score) ?> weighted score</span></div>
            <div><strong>Trend</strong><span><?= count($allResults) >= 3 ? 'History available' : 'Needs more receipts' ?></span></div>
            <div><strong>Anomaly</strong><span><?= count($anomalies) ?> Z-score flags</span></div>
            <div><strong>Recommendation</strong><span><?= count($recommendations) ?> advice items</span></div>
        </div>
    </section>

    <section class="grid dashboard-grid">
        <article class="panel span-8">
            <h2>Score Breakdown</h2>
            <div class="breakdown-bars">
                <?php foreach (($result['health_score']['breakdown'] ?? []) as $key => $value): ?>
                    <div class="bar-row">
                        <span><?= e(ucwords($key)) ?></span>
                        <div class="bar-track"><i style="width: <?= e((string)max(0, min(100, (float)$value))) ?>%"></i></div>
                        <strong><?= e($value) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel span-4">
            <h2>Family Risk Context</h2>
            <dl class="facts compact">
                <div><dt>Age group</dt><dd><?= e($family['age_group'] ?? 'not set') ?></dd></div>
                <div><dt>Conditions</dt><dd><?= e(condition_text($family)) ?></dd></div>
                <div><dt>Normalization</dt><dd>Per <?= e($family['family_size'] ?? 1) ?> members</dd></div>
                <div><dt>Database receipt</dt><dd><?= e($result['database_receipt_id'] ?? 'JSON only') ?></dd></div>
            </dl>
            <?php if (isset($result['database_warning'])): ?>
                <p class="warning-text"><?= e($result['database_warning']) ?></p>
            <?php endif; ?>
        </article>
    </section>

    <section class="grid two">
        <article class="panel">
            <h2>Profile and Health Analysis</h2>
            <p class="muted"><?= e($profileAnalysis['summary'] ?? 'No profile analysis was attached to this report.') ?></p>
            <div class="module-list compact-list">
                <?php foreach (($profileAnalysis['focus'] ?? []) as $label => $detail): ?>
                    <div>
                        <strong><?= e($label) ?></strong>
                        <span><?= e($detail) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($profileAnalysis['focus'])): ?>
                    <div><strong>Balanced nutrition</strong><span>Add a health profile to personalize receipt scoring.</span></div>
                <?php endif; ?>
            </div>
        </article>

        <article class="panel">
            <h2>User Health Notes</h2>
            <dl class="facts compact">
                <div><dt>Role</dt><dd><?= e(ucfirst((string)($profileContext['role'] ?? 'user'))) ?></dd></div>
                <div><dt>Household</dt><dd><?= e($profileContext['household_name'] ?? 'Not saved') ?></dd></div>
                <div><dt>Goal</dt><dd><?= e(str_replace('_', ' ', (string)($profileContext['diet_goal'] ?? 'balanced'))) ?></dd></div>
                <div><dt>Activity</dt><dd><?= e(str_replace('_', ' ', (string)($profileContext['activity_level'] ?? 'moderate'))) ?></dd></div>
            </dl>
            <?php if (trim((string)($profileContext['health_notes'] ?? '')) !== ''): ?>
                <pre class="receipt-text profile-note-box"><?= e($profileContext['health_notes']) ?></pre>
            <?php else: ?>
                <p class="muted">No free-text health note was provided for this report.</p>
            <?php endif; ?>
        </article>
    </section>

    <section class="grid two">
        <article class="panel">
            <h2>Nutrition Risk Matrix</h2>
            <div class="risk-matrix">
                <?php foreach (nutrient_rows($perPerson) as $row): ?>
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
            <h2>Category Distribution</h2>
            <div class="category-grid">
                <?php foreach ($categories as $category => $quantity): ?>
                    <div>
                        <span><?= e(ucwords($category)) ?></span>
                        <strong><?= e($quantity) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>

    <section class="grid two">
        <article class="panel">
            <h2>Normalized Items</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Category</th>
                            <th>Risk</th>
                            <th>Confidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
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
        </article>

        <article class="panel">
            <h2>Extracted Receipt Text</h2>
            <pre class="receipt-text"><?= e($result['extracted_text'] ?? 'No extracted text available.') ?></pre>
        </article>
    </section>

    <section class="grid two">
        <article class="panel">
            <h2>NLP Evidence Lines</h2>
            <ul class="insight-list">
                <?php foreach ($items as $item): ?>
                    <li>
                        <strong><?= e($item['name'] ?? '') ?></strong>
                        <span><?= e($item['raw_line'] ?? 'No raw line evidence stored.') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </article>

        <article class="panel">
            <h2>Unmatched OCR Lines</h2>
            <?php if (empty($result['unmatched_lines'])): ?>
                <p>No unmatched lines. All detected receipt lines were mapped to the food database.</p>
            <?php else: ?>
                <ul class="insight-list">
                    <?php foreach ($result['unmatched_lines'] as $line): ?>
                        <li><?= e($line) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </section>

    <section class="grid two">
        <article class="panel">
            <h2>Anomaly Detection</h2>
            <?php if (!$anomalies): ?>
                <p>No unusual purchase quantities detected for this receipt.</p>
            <?php else: ?>
                <ul class="insight-list">
                    <?php foreach ($anomalies as $anomaly): ?>
                        <li>
                            <strong><?= e($anomaly['item'] ?? 'Item') ?></strong>
                            <span><?= e($anomaly['message'] ?? '') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="panel">
            <h2>Recommendation Engine</h2>
            <ul class="insight-list">
                <?php foreach ($recommendations as $recommendation): ?>
                    <li><?= e($recommendation) ?></li>
                <?php endforeach; ?>
            </ul>
        </article>
    </section>

    <section class="panel">
        <h2>Trend Snapshot</h2>
        <?php if (count($history) < 2): ?>
            <p class="muted">Upload at least two more receipts to build a stronger weekly/monthly trend story.</p>
        <?php else: ?>
            <div class="trend-strip">
                <?php foreach ($history as $point): ?>
                    <?php $pointScore = score_value($point); ?>
                    <a href="dashboard.php?id=<?= e($point['_id']) ?>" title="<?= e(date('M d, H:i', $point['_created_at'])) ?>">
                        <i style="height: <?= e((string)max(8, min(100, $pointScore))) ?>%"></i>
                        <span><?= e($pointScore) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php render_page_end(); ?>
