<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/profile.php';

$healthProfile = load_user_health_profile();
$profileConditions = $healthProfile['conditions'] ?? [];

render_page_start('Upload Receipt', 'upload');
page_hero(
    'Receipt intelligence workspace',
    'Analyze grocery receipts as household nutrition data',
    'This page collects the family context and receipt evidence, then sends it through OCR, NLP, nutrition graph mapping, scoring, anomaly detection, and recommendations.',
    '<a class="button ghost" href="dashboard.php">View latest report</a>'
);
?>

<section class="grid dashboard-grid">
    <article class="panel span-8">
        <h2>Receipt Analysis Input</h2>
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

            <label>
                <span>Extra health notes for this analysis</span>
                <textarea name="health_notes" rows="5" placeholder="Tell the AI about cravings, symptoms, doctor advice, recent goals, or anything health related."><?= e($healthProfile['health_notes'] ?? '') ?></textarea>
            </label>

            <label class="upload-box">
                <span>Receipt image or text file</span>
                <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.txt" required>
                <small>Use a .txt file for reliable testing now. Image OCR can be connected with Tesseract or EasyOCR.</small>
            </label>

            <button class="button primary" type="submit">Run AI Analysis</button>
        </form>
    </article>

    <aside class="panel span-4">
        <h2>Project Modules</h2>
        <div class="module-list">
            <div><strong>01 OCR</strong><span>Receipt text extraction</span></div>
            <div><strong>02 NLP</strong><span>Item cleanup and normalization</span></div>
            <div><strong>03 Knowledge Graph</strong><span>Food, nutrient, risk mapping</span></div>
            <div><strong>04 Scoring</strong><span>Weighted household score</span></div>
            <div><strong>05 Trends</strong><span>Weekly/monthly purchase patterns</span></div>
            <div><strong>06 Anomalies</strong><span>Z-score unusual purchase flags</span></div>
            <div><strong>07 Recommendations</strong><span>Explainable healthy shopping advice</span></div>
            <div><strong>08 Analytics</strong><span>Moving averages and category dominance</span></div>
            <div><strong>09 OCR Review</strong><span>Human correction workflow</span></div>
            <div><strong>10 Reports</strong><span>Printable and exportable evidence</span></div>
        </div>
    </aside>
</section>

<section class="panel">
    <h2>Active Health Profile</h2>
    <div class="module-list">
        <div><strong><?= e($healthProfile['household_name'] ?? 'My Household') ?></strong><span><?= e($healthProfile['family_size'] ?? 1) ?> member(s), <?= e(str_replace('_', ' ', (string)($healthProfile['diet_goal'] ?? 'balanced'))) ?> goal</span></div>
        <div><strong>AI focus</strong><span><?= e(implode(', ', array_keys($healthProfile['analysis']['focus'] ?? ['Balanced nutrition' => true]))) ?></span></div>
        <div><strong>Profile page</strong><span><a class="table-link" href="profile_setup.php">Update details and health notes</a></span></div>
    </div>
</section>

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

<?php render_page_end(); ?>
