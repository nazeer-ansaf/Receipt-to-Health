<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';

$resultId = $_GET['id'] ?? 'latest';
$result = load_result($resultId);
$allResults = load_all_results();
$history = array_reverse(array_slice($allResults, 0, 8));

render_page_start('Dashboard', 'dashboard');
page_hero(
    'Your result',
    'Receipt Health Result',
    'Start with the score, read the priority actions, then use the proof sections only when you need details.',
    '<a class="button primary" href="index.php">Analyze another receipt</a><a class="button ghost" href="reports.php?id=' . e((string)$resultId) . '">Download report</a>'
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
        $recommendationCards = recommendation_cards($result);
        $anomalies = $result['anomalies'] ?? [];
        $profileContext = $result['profile_context'] ?? [];
        $profileAnalysis = $result['profile_analysis'] ?? [];
        $noteFlags = $result['health_note_analysis']['flags'] ?? [];
        $shoppingAlternatives = $result['shopping_alternatives'] ?? [];
        $riskRows = nutrient_rows($perPerson);
        $weeklyTrend = weekly_score_trend($allResults);
        $scoreExplanation = score_explanation($result);
        $priorityAlerts = is_array($result['priority_alerts'] ?? null) ? $result['priority_alerts'] : [];
        $receiptAsset = $result['receipt_asset'] ?? ($result['correction_context']['original_asset'] ?? []);
        $correctionContext = $result['correction_context'] ?? [];
        $familyMembers = is_array($profileContext['family_members'] ?? null) ? $profileContext['family_members'] : [];
        $assistantData = [
            'score' => $score,
            'label' => $result['health_score']['label'] ?? 'Unknown',
            'breakdown' => $result['health_score']['breakdown'] ?? [],
            'score_explanation' => $scoreExplanation,
            'priority_alerts' => $priorityAlerts,
            'nutrients' => $perPerson,
            'risk_rows' => $riskRows,
            'items' => $items,
            'recommendations' => array_map(static fn($card) => $card['advice'] ?? '', $recommendationCards),
            'health_note_flags' => $noteFlags,
            'anomalies' => $anomalies,
            'shopping_alternatives' => $shoppingAlternatives,
            'weekly_trend' => $weeklyTrend,
        ];
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
            <strong><?= count($anomalies) + count($recommendationCards) ?></strong>
            <small>signals found</small>
        </article>
    </section>

    <section class="panel next-step-panel">
        <h2>What To Do Next</h2>
        <div class="next-action-grid">
            <a href="#priority-actions">
                <strong>Fix first</strong>
                <span>Start with the highest-priority alert.</span>
            </a>
            <a href="#shopping-swaps">
                <strong>Swap risky items</strong>
                <span>See healthier and budget-friendly replacements.</span>
            </a>
            <a href="ocr_review.php">
                <strong>Correct items</strong>
                <span>Fix OCR or quantity mistakes before trusting the score.</span>
            </a>
            <a href="api/export_report.php?format=pdf&id=<?= e($result['_id'] ?? 'latest') ?>">
                <strong>Save PDF</strong>
                <span>Download a clean report for demo or sharing.</span>
            </a>
        </div>
    </section>

    <section class="panel report-assistant" data-report-assistant>
        <h2>Chatbox Assistant</h2>
        <div class="assistant-log" data-assistant-log>
            <div class="assistant-message bot">
                <strong>Assistant</strong>
                <p>Ask about this report, for example: Why is my score low?</p>
            </div>
        </div>
        <form class="assistant-form" data-assistant-form>
            <input type="text" name="question" autocomplete="off" placeholder="Why is my score low?">
            <button class="button primary" type="submit">Ask</button>
        </form>
        <script type="application/json" id="report-assistant-data"><?= json_encode($assistantData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </section>

    <section class="grid dashboard-grid">
        <article class="panel span-7">
            <h2>Plain-Language Score Explanation</h2>
            <p class="score-explanation-lead"><?= e($scoreExplanation['summary'] ?? 'Score explanation is not available for this report.') ?></p>
            <ul class="insight-list">
                <?php foreach (($scoreExplanation['reasons'] ?? []) as $reason): ?>
                    <li><?= e($reason) ?></li>
                <?php endforeach; ?>
            </ul>
        </article>

        <article id="priority-actions" class="panel span-5">
            <h2>Priority Alerts</h2>
            <div class="priority-alert-list">
                <?php foreach ($priorityAlerts as $alert): ?>
                    <article class="priority-alert <?= e(priority_class((string)($alert['priority'] ?? ''))) ?>">
                        <span><?= e($alert['priority'] ?? 'Watch') ?></span>
                        <strong><?= e($alert['title'] ?? '') ?></strong>
                        <p><?= e($alert['detail'] ?? '') ?></p>
                        <small><?= e($alert['proof'] ?? '') ?></small>
                    </article>
                <?php endforeach; ?>
                <?php if (!$priorityAlerts): ?>
                    <p class="muted">New reports will include ranked Fix first, Watch, and Good habit alerts.</p>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <section class="grid dashboard-grid">
        <article class="panel span-5">
            <h2>Receipt Image and OCR Confidence</h2>
            <?php if (!empty($receiptAsset['is_image']) && !empty($receiptAsset['web_path'])): ?>
                <img class="receipt-preview-image" src="<?= e($receiptAsset['web_path']) ?>" alt="Uploaded receipt image preview">
            <?php else: ?>
                <div class="receipt-preview-placeholder">Text receipt or corrected item entry</div>
            <?php endif; ?>
            <dl class="facts compact">
                <div><dt>OCR engine</dt><dd><?= e($result['ocr_status']['engine'] ?? 'not stored') ?></dd></div>
                <div><dt>OCR confidence</dt><dd><?= e($result['ocr_status']['confidence_label'] ?? 'n/a') ?> <?= isset($result['ocr_status']['confidence']) ? '(' . e(round((float)$result['ocr_status']['confidence'] * 100)) . '%)' : '' ?></dd></div>
                <div><dt>Original file</dt><dd><?= e($receiptAsset['original_name'] ?? 'not stored') ?></dd></div>
            </dl>
        </article>

        <article class="panel span-7">
            <h2>Condition-Specific Scoring</h2>
            <div class="weight-list compact-list">
                <?php foreach (($result['health_score']['weight_adjustments'] ?? []) as $adjustment): ?>
                    <div><strong>Applied</strong><span><?= e($adjustment) ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($result['health_score']['weights']) && is_array($result['health_score']['weights'])): ?>
                <div class="score-grid compact-score-grid">
                    <?php foreach ($result['health_score']['weights'] as $weightName => $weightValue): ?>
                        <div class="score-chip"><span><?= e(ucwords((string)$weightName)) ?> weight</span><strong><?= e($weightValue) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <?php if (!empty($correctionContext)): ?>
        <section class="grid two">
            <article class="panel">
                <h2>Original OCR Before Correction</h2>
                <pre class="receipt-text"><?= e($correctionContext['original_extracted_text'] ?? 'No original OCR text stored.') ?></pre>
            </article>
            <article class="panel">
                <h2>Corrected Items After Review</h2>
                <pre class="receipt-text"><?= e($correctionContext['corrected_text'] ?? $result['extracted_text'] ?? 'No corrected text stored.') ?></pre>
            </article>
        </section>
    <?php endif; ?>

    <details class="technical-evidence">
        <summary>Show technical evidence for demo or viva</summary>

    <section class="panel">
        <h2>Seven-Layer AI Pipeline Evidence</h2>
        <div class="pipeline">
            <div><strong>OCR</strong><span><?= trim((string)($result['extracted_text'] ?? '')) !== '' ? 'Text extracted' : 'Waiting for OCR text' ?></span></div>
            <div><strong>NLP</strong><span><?= count($items) ?> items normalized</span></div>
            <div><strong>Knowledge Graph</strong><span><?= count($categories) ?> food categories mapped</span></div>
            <div><strong>Scoring</strong><span><?= e($score) ?> weighted score</span></div>
            <div><strong>Trend</strong><span><?= count($allResults) >= 3 ? 'History available' : 'Needs more receipts' ?></span></div>
            <div><strong>Anomaly</strong><span><?= count($anomalies) ?> Z-score flags</span></div>
            <div><strong>Recommendation</strong><span><?= count($recommendationCards) ?> advice items with proof</span></div>
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
                <div><dt>Medical records</dt><dd><?= e($profileContext['medical_record_count'] ?? 0) ?> uploaded</dd></div>
            </dl>
            <?php if (!empty($profileContext['medical_record_titles']) && is_array($profileContext['medical_record_titles'])): ?>
                <p class="muted">Attached context: <?= e(implode(', ', array_map('strval', $profileContext['medical_record_titles']))) ?></p>
            <?php endif; ?>
            <?php if (trim((string)($profileContext['health_notes'] ?? '')) !== ''): ?>
                <pre class="receipt-text profile-note-box"><?= e($profileContext['health_notes']) ?></pre>
            <?php else: ?>
                <p class="muted">No free-text health note was provided for this report.</p>
            <?php endif; ?>

            <?php if (!empty($noteFlags) && is_array($noteFlags)): ?>
                <div class="note-flag-grid">
                    <?php foreach ($noteFlags as $flag): ?>
                        <div>
                            <strong><?= e($flag['label'] ?? '') ?></strong>
                            <span><?= e($flag['proof'] ?? '') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($familyMembers): ?>
                <div class="family-member-mini-grid">
                    <?php foreach ($familyMembers as $member): ?>
                        <article>
                            <strong><?= e($member['name'] ?? 'Family member') ?></strong>
                            <span><?= e(str_replace('_', ' ', (string)($member['age_group'] ?? 'adult'))) ?></span>
                            <small><?= e(condition_text(['conditions' => $member['conditions'] ?? []])) ?></small>
                            <?php if (trim((string)($member['notes'] ?? '')) !== ''): ?>
                                <p><?= e($member['notes']) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <section class="grid two">
        <article class="panel">
            <h2>Nutrition Risk Matrix</h2>
            <div class="risk-matrix">
                <?php foreach ($riskRows as $row): ?>
                    <div class="risk-row">
                        <div>
                            <strong><?= e($row['label']) ?></strong>
                            <span><?= e($row['status']) ?>, target <?= e($row['target']) ?> <?= e($row['unit']) ?></span>
                        </div>
                        <span class="risk-badge <?= e($row['level_class']) ?>"><?= e($row['level']) ?></span>
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
                                <td><span class="risk-badge <?= e(risk_text_class((string)($item['risk'] ?? ''))) ?>"><?= e($item['risk'] ?? '') ?></span></td>
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
            <h2>Recommendation Engine With Proof</h2>
            <div class="recommendation-proof-list">
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

    </details>

    <section id="shopping-swaps" class="panel">
        <h2>Healthier Shopping Alternatives</h2>
        <?php if (empty($shoppingAlternatives) || !is_array($shoppingAlternatives)): ?>
            <p class="muted">No risky items with replacement suggestions were detected in this receipt.</p>
        <?php else: ?>
            <div class="alternative-grid">
                <?php foreach ($shoppingAlternatives as $alternative): ?>
                    <article class="alternative-card">
                        <div>
                            <strong><?= e($alternative['item'] ?? 'Item') ?></strong>
                            <span class="risk-badge <?= e(risk_text_class((string)($alternative['risk'] ?? ''))) ?>"><?= e($alternative['risk'] ?? 'risk') ?></span>
                        </div>
                        <p><?= e($alternative['proof'] ?? '') ?></p>
                        <div class="swap-list">
                            <?php foreach (($alternative['alternatives'] ?? []) as $swap): ?>
                                <span><?= e($swap) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($alternative['budget_options']) && is_array($alternative['budget_options'])): ?>
                            <div class="budget-swap-list">
                                <?php foreach ($alternative['budget_options'] as $budgetOption): ?>
                                    <span><b><?= e($budgetOption['name'] ?? '') ?></b><?= e($budgetOption['budget'] ?? 'moderate cost') ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Weekly Trend Chart</h2>
        <?php if (count($weeklyTrend) < 2): ?>
            <p class="muted">Upload receipts across at least two weeks to build a stronger weekly trend chart.</p>
        <?php else: ?>
            <?php
                $chartCount = count($weeklyTrend);
                $chartPoints = [];
                foreach ($weeklyTrend as $index => $point) {
                    $x = $chartCount > 1 ? 44 + ($index * (552 / ($chartCount - 1))) : 320;
                    $y = 178 - (max(0, min(100, (float)$point['score'])) * 1.42);
                    $chartPoints[] = round($x, 1) . ',' . round($y, 1);
                }
            ?>
            <div class="weekly-chart-wrap">
                <svg class="weekly-chart" viewBox="0 0 640 220" role="img" aria-label="Weekly health score trend chart">
                    <line x1="44" y1="178" x2="596" y2="178"></line>
                    <line x1="44" y1="36" x2="44" y2="178"></line>
                    <polyline points="<?= e(implode(' ', $chartPoints)) ?>"></polyline>
                    <?php foreach ($weeklyTrend as $index => $point): ?>
                        <?php
                            $x = $chartCount > 1 ? 44 + ($index * (552 / ($chartCount - 1))) : 320;
                            $y = 178 - (max(0, min(100, (float)$point['score'])) * 1.42);
                        ?>
                        <circle cx="<?= e(round($x, 1)) ?>" cy="<?= e(round($y, 1)) ?>" r="5"></circle>
                        <text x="<?= e(round($x, 1)) ?>" y="<?= e(min(202, $y + 24)) ?>"><?= e($point['score']) ?></text>
                    <?php endforeach; ?>
                </svg>
                <div class="weekly-chart-labels">
                    <?php foreach ($weeklyTrend as $point): ?>
                        <span><?= e($point['label']) ?><small><?= e($point['count']) ?> receipt<?= (int)$point['count'] === 1 ? '' : 's' ?></small></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (count($history) >= 2): ?>
            <div class="trend-strip compact-trend">
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
