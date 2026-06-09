<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/profile.php';

$healthProfile = load_user_health_profile();
$profileConditions = $healthProfile['conditions'] ?? [];

render_page_start('OCR Review', 'ocr');
page_hero(
    'Human-in-the-loop AI',
    'OCR Review and Correction Workbench',
    'Final-year systems should show risk handling. This module lets a user correct OCR text before sending it into NLP, scoring, anomaly detection, and recommendations.'
);
?>

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

<?php render_page_end(); ?>
