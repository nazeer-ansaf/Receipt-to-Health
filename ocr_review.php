<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/profile.php';
require_once __DIR__ . '/includes/analysis.php';
require_once __DIR__ . '/includes/results.php';

$healthProfile = load_user_health_profile();
$profileConditions = $healthProfile['conditions'] ?? [];
$draftId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['draft'] ?? ''));
$draft = $draftId !== '' ? load_ocr_draft($draftId) : null;
$draftResult = is_array($draft['analysis_result'] ?? null) ? $draft['analysis_result'] : null;
$draftItems = $draftResult['items'] ?? [];
$draftFamilySize = (int)($draft['family_size'] ?? $healthProfile['family_size'] ?? 4);
$draftAgeGroup = (string)($draft['age_group'] ?? $healthProfile['age_group'] ?? 'mixed');
$draftConditions = is_array($draft['conditions'] ?? null) ? $draft['conditions'] : $profileConditions;
$draftHealthNotes = (string)($draft['health_notes'] ?? $healthProfile['health_notes'] ?? '');

render_page_start('OCR Review', 'ocr');
page_hero(
    'Fix mistakes',
    'Review Detected Items',
    'OCR is useful, but it can misread receipts. Check the item names and quantities here before trusting the final score.',
    '<a class="button ghost" href="index.php">Upload another receipt</a>'
);
?>

<?php if ($draftResult): ?>
<?php page_steps([
    ['title' => 'Check receipt', 'text' => 'Compare the preview with detected items.'],
    ['title' => 'Fix quantities', 'text' => 'Example: change soda 3 to soda 1.'],
    ['title' => 'Analyze', 'text' => 'Save the corrected basket as the final report.'],
]); ?>
<section class="grid dashboard-grid">
    <article class="panel span-8">
        <h2>Fix Detected Items</h2>
        <form action="api/analyze_items.php" method="post" data-item-correction-form>
            <input type="hidden" name="draft_id" value="<?= e($draftId) ?>">

            <div class="ocr-status-card">
                <strong><?= e(ucfirst((string)($draftResult['ocr_status']['engine'] ?? 'OCR'))) ?></strong>
                <span><?= e($draftResult['ocr_status']['message'] ?? 'OCR output is ready for review.') ?></span>
            </div>

            <div class="table-wrap">
                <table class="item-editor-table">
                    <thead>
                        <tr>
                            <th>Correct item</th>
                            <th>Qty</th>
                            <th>Detected category</th>
                            <th>Risk</th>
                            <th>Raw proof</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody data-item-editor-body>
                        <?php if (!$draftItems): ?>
                            <tr>
                                <td><input type="text" name="item_name[]" placeholder="food item"></td>
                                <td><input type="number" name="quantity[]" min="0" step="0.1" value="1"></td>
                                <td colspan="3" class="muted">No items were confidently detected. Add rows manually.</td>
                                <td><button class="mini-icon-button" type="button" data-remove-item-row title="Remove row">x</button></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($draftItems as $item): ?>
                            <tr>
                                <td><input type="text" name="item_name[]" value="<?= e($item['name'] ?? '') ?>" required></td>
                                <td><input type="number" name="quantity[]" min="0" step="0.1" value="<?= e($item['quantity'] ?? 1) ?>" required></td>
                                <td><?= e($item['category'] ?? '') ?></td>
                                <td><span class="risk-badge <?= e(risk_text_class((string)($item['risk'] ?? ''))) ?>"><?= e($item['risk'] ?? 'not rated') ?></span></td>
                                <td class="proof-cell"><?= e($item['raw_line'] ?? '') ?></td>
                                <td><button class="mini-icon-button" type="button" data-remove-item-row title="Remove row">x</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button class="button ghost" type="button" data-add-item-row>Add item</button>

            <label>
                <span>Unmatched or extra receipt text</span>
                <textarea name="extra_receipt_text" rows="4" placeholder="Add any OCR lines that were missed."><?= e(implode(PHP_EOL, array_map('strval', $draftResult['unmatched_lines'] ?? []))) ?></textarea>
            </label>

            <div class="grid two">
                <label>
                    <span>Family members</span>
                    <input type="number" name="family_size" min="1" max="20" value="<?= e($draftFamilySize) ?>" required>
                </label>
                <label>
                    <span>Age group</span>
                    <select name="age_group">
                        <?php foreach (['adult' => 'Adults', 'children' => 'Children', 'elderly' => 'Elderly', 'mixed' => 'Mixed family'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $draftAgeGroup === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <fieldset>
                <legend>Health conditions</legend>
                <div class="chips">
                    <?php foreach (['diabetes' => 'Diabetes risk', 'hypertension' => 'Hypertension', 'cholesterol' => 'High cholesterol', 'none' => 'None'] as $value => $label): ?>
                        <label>
                            <input type="checkbox" name="conditions[]" value="<?= e($value) ?>" <?= in_array($value, $draftConditions, true) ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <label>
                <span>Extra health notes for this analysis</span>
                <textarea name="health_notes" rows="5" placeholder="Example: pregnant, diabetic, only one person, child, low salt..."><?= e($draftHealthNotes) ?></textarea>
            </label>

            <button class="button primary" type="submit">Save and analyze corrected items</button>
        </form>
    </article>

    <aside class="panel span-4">
        <h2>Receipt Preview</h2>
        <?php if (in_array((string)($draft['extension'] ?? ''), ['jpg', 'jpeg', 'png', 'webp'], true) && !empty($draft['source_web_path'])): ?>
            <img class="receipt-preview-image" src="<?= e($draft['source_web_path']) ?>" alt="Receipt image pending correction">
        <?php else: ?>
            <div class="receipt-preview-placeholder">Text receipt draft</div>
        <?php endif; ?>

        <h2>OCR Evidence Before Correction</h2>
        <dl class="facts compact">
            <div><dt>Engine</dt><dd><?= e($draftResult['ocr_status']['engine'] ?? 'OCR') ?></dd></div>
            <div><dt>Confidence</dt><dd><?= e($draftResult['ocr_status']['confidence_label'] ?? 'n/a') ?> <?= isset($draftResult['ocr_status']['confidence']) ? '(' . e(round((float)$draftResult['ocr_status']['confidence'] * 100)) . '%)' : '' ?></dd></div>
        </dl>
        <pre class="receipt-text"><?= e($draftResult['extracted_text'] ?? 'No extracted text available.') ?></pre>

        <?php $noteFlags = $draftResult['health_note_analysis']['flags'] ?? []; ?>
        <?php if ($noteFlags): ?>
            <h2>Detected Health Notes</h2>
            <div class="module-list compact-list">
                <?php foreach ($noteFlags as $flag): ?>
                    <div><strong><?= e($flag['label'] ?? '') ?></strong><span><?= e($flag['proof'] ?? '') ?></span></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </aside>
</section>
<?php else: ?>
<?php page_steps([
    ['title' => 'Paste text', 'text' => 'Use receipt text or a corrected OCR draft.'],
    ['title' => 'Add context', 'text' => 'Keep health notes short and specific.'],
    ['title' => 'Analyze', 'text' => 'Generate the final result.'],
]); ?>
<section class="grid dashboard-grid">
    <article class="panel span-8">
        <h2>Corrected Receipt Text</h2>
        <form action="api/analyze_text.php" method="post">
            <label>
                <span>Paste or correct OCR text</span>
                <textarea name="receipt_text" rows="14" required>milk 2
bread 2
soda 3
chips 2
apples 6
vegetables 3</textarea>
            </label>

            <div class="grid two">
                <label>
                    <span>Family members</span>
                    <input type="number" name="family_size" min="1" max="20" value="<?= e($healthProfile['family_size'] ?? 4) ?>" required>
                </label>
                <label>
                    <span>Age group</span>
                    <select name="age_group">
                        <?php foreach (['adult' => 'Adults', 'children' => 'Children', 'elderly' => 'Elderly', 'mixed' => 'Mixed family'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($healthProfile['age_group'] ?? 'mixed') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <fieldset>
                <legend>Health conditions</legend>
                <div class="chips">
                    <?php foreach (['diabetes' => 'Diabetes risk', 'hypertension' => 'Hypertension', 'cholesterol' => 'High cholesterol'] as $value => $label): ?>
                        <label>
                            <input type="checkbox" name="conditions[]" value="<?= e($value) ?>" <?= in_array($value, $profileConditions, true) ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <label>
                <span>Extra health notes for this analysis</span>
                <textarea name="health_notes" rows="5" placeholder="Tell the AI anything health related for this corrected receipt."><?= e($healthProfile['health_notes'] ?? '') ?></textarea>
            </label>

            <button class="button primary" type="submit">Analyze corrected text</button>
        </form>
    </article>

    <aside class="panel span-4">
        <h2>OCR Risk Controls</h2>
        <div class="module-list">
            <div><strong>Manual correction</strong><span>User can fix item names before scoring.</span></div>
            <div><strong>Unmatched lines</strong><span>Pipeline returns lines that could not be mapped.</span></div>
            <div><strong>Confidence evidence</strong><span>Matched items include raw-line evidence.</span></div>
            <div><strong>Partial data warning</strong><span>Unmapped items can be reviewed before final use.</span></div>
        </div>
    </aside>
</section>
<?php endif; ?>

<?php render_page_end(); ?>
