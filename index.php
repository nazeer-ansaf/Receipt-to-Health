<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/profile.php';

$healthProfile = load_user_health_profile();
$profileConditions = $healthProfile['conditions'] ?? [];

render_page_start('Upload Receipt', 'upload');
page_hero(
    'Start here',
    'Upload a receipt. Get clear health actions.',
    'Add a receipt and a short health note. The app will detect the food items, let you fix mistakes, and explain what to improve first.',
    '<a class="button primary" href="api/demo_mode.php?mode=final">Try instant demo</a><a class="button ghost" href="api/demo_mode.php?mode=review">Try item correction</a><a class="button ghost" href="dashboard.php">Latest result</a>'
);
?>

<?php page_steps([
    ['title' => 'Upload receipt', 'text' => 'Use an image or text receipt.'],
    ['title' => 'Review items', 'text' => 'Fix quantities before scoring.'],
    ['title' => 'Read actions', 'text' => 'See what to reduce, replace, or keep.'],
]); ?>

<section class="grid dashboard-grid">
    <article class="panel span-8">
        <h2>Analyze Your Receipt</h2>
        <p class="muted">Keep this simple: choose the receipt, add the household context, then run analysis. Leave item review on if you want to correct OCR mistakes first.</p>
        <form id="receipt-form" action="api/process_receipt.php" method="post" enctype="multipart/form-data">
            <div class="grid two">
                <label>
                    <span>Family members</span>
                    <input type="number" name="family_size" min="1" max="20" value="<?= e($healthProfile['family_size'] ?? 4) ?>" required>
                </label>

                <label>
                    <span>Average age group</span>
                    <select name="age_group" required>
                        <?php foreach (['adult' => 'Adults', 'children' => 'Children', 'elderly' => 'Elderly', 'mixed' => 'Mixed family'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($healthProfile['age_group'] ?? 'mixed') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <fieldset>
                <legend>Health conditions</legend>
                <div class="chips">
                    <?php foreach (['diabetes' => 'Diabetes risk', 'hypertension' => 'Hypertension', 'cholesterol' => 'High cholesterol', 'none' => 'None'] as $value => $label): ?>
                        <label>
                            <input type="checkbox" name="conditions[]" value="<?= e($value) ?>" <?= in_array($value, $profileConditions, true) ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <label class="upload-box" data-empty-label="No receipt selected">
                <span>Receipt image or text file</span>
                <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.txt" required>
                <small>Photo receipts work best when the image is bright, flat, and readable.</small>
            </label>

            <label class="inline-option">
                <input type="checkbox" name="review_items" value="1" checked>
                <span>Review detected items before final analysis</span>
            </label>

            <label class="receipt-context-box">
                <span>Health note for this receipt</span>
                <textarea name="health_notes" rows="4" placeholder="Example: 2 months pregnant, this receipt is only for one person, buying for a child, low-salt diet, diabetic patient, gym diet, medicine restrictions..."><?= e($healthProfile['health_notes'] ?? '') ?></textarea>
                <small>Words like pregnant, diabetic, child, only one person, or low salt make the advice more personal.</small>
            </label>

            <button class="button primary" type="submit">Analyze receipt</button>
        </form>
    </article>

    <aside class="panel span-4">
        <h2>What You’ll Get</h2>
        <div class="next-action-grid compact-actions">
            <a href="dashboard.php">
                <strong>Clear score</strong>
                <span>See why it is high or low.</span>
            </a>
            <a href="ocr_review.php">
                <strong>Fix mistakes</strong>
                <span>Edit detected items before final scoring.</span>
            </a>
            <a href="reports.php">
                <strong>Export report</strong>
                <span>Download PDF, CSV, or JSON.</span>
            </a>
        </div>
    </aside>
</section>

<section class="panel friendly-callout">
    <h2>Active Health Profile</h2>
    <div class="module-list">
        <div><strong><?= e($healthProfile['household_name'] ?? 'My Household') ?></strong><span><?= e($healthProfile['family_size'] ?? 1) ?> member(s), <?= e(str_replace('_', ' ', (string)($healthProfile['diet_goal'] ?? 'balanced'))) ?> goal</span></div>
        <div><strong>AI focus</strong><span><?= e(implode(', ', array_keys($healthProfile['analysis']['focus'] ?? ['Balanced nutrition' => true]))) ?></span></div>
        <div><strong>Profile page</strong><span><a class="table-link" href="profile_setup.php">Update details and health notes</a></span></div>
    </div>
</section>

<details class="technical-evidence">
    <summary>Show demo and technical details</summary>
    <section class="grid three">
        <article class="panel feature-card">
            <span class="feature-number">A</span>
            <h2>Family Normalization</h2>
            <p>Quantities are divided per family member so a receipt is judged as household-level nutrition, not a single-person meal log.</p>
        </article>
        <article class="panel feature-card">
            <span class="feature-number">B</span>
            <h2>Risk Weighting</h2>
            <p>Diabetes, hypertension, cholesterol, and age group adjust the scoring weights for more realistic personalized alerts.</p>
        </article>
        <article class="panel feature-card">
            <span class="feature-number">C</span>
            <h2>Explainability</h2>
            <p>The dashboard shows score breakdown, risk reasons, anomalies, and the exact recommendation evidence.</p>
        </article>
    </section>

    <section class="panel">
        <h2>Quick Test Data</h2>
        <p class="muted">For demonstrations, upload this sample file first:</p>
        <code class="path-code">C:\xampp\htdocs\receipt-to-health\samples\demo_receipt.txt</code>
        <p class="muted">For a larger demonstration, use:</p>
        <code class="path-code">C:\xampp\htdocs\receipt-to-health\samples\final_year_demo_receipt.txt</code>
    </section>
</details>

<?php render_page_end(); ?>
