<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/profile.php';
require_once __DIR__ . '/includes/results.php';

$healthProfile = load_user_health_profile();
$profileConditions = $healthProfile['conditions'] ?? [];
$recentResults = array_slice(load_all_results(), 0, 3);

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
        <form id="receipt-form" action="api/process_receipt.php" method="post" enctype="multipart/form-data" data-analysis-form>
            <?= csrf_field() ?>
            <div class="analysis-mode-switch" role="group" aria-label="Choose receipt input">
                <button class="button ghost is-active" type="button" data-analysis-mode="image" aria-pressed="true">Receipt image</button>
                <button class="button ghost" type="button" data-analysis-mode="text" aria-pressed="false">Paste receipt text</button>
            </div>
            <div class="grid two">
                <label>
                    <span>Family members</span>
                    <input type="number" name="family_size" min="1" max="20" value="<?= e($healthProfile['family_size'] ?? 1) ?>" required>
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

            <div data-analysis-panel="image">
                <label class="upload-box" tabindex="0" data-empty-label="Drop receipt here or choose a file" aria-describedby="upload-help">
                    <span data-upload-title>Drop receipt here</span>
                    <input type="file" name="receipt" accept="image/*,.txt" capture="environment" required>
                    <small id="upload-help">Click to choose, drag and drop, or use your camera on mobile. JPG, PNG, WEBP, or TXT.</small>
                    <small class="upload-state" data-upload-state aria-live="polite">Ready</small>
                </label>
                <button class="button ghost camera-capture-button" type="button" data-open-camera>Use laptop camera</button>
                <div class="camera-capture" data-camera-panel hidden>
                    <video data-camera-video autoplay playsinline muted aria-label="Live laptop camera preview"></video>
                    <div class="camera-capture-actions">
                        <button class="button primary" type="button" data-capture-camera>Take photo</button>
                        <button class="button ghost" type="button" data-close-camera>Cancel</button>
                    </div>
                    <p class="muted" data-camera-message>Camera access is used only after you choose to take a photo.</p>
                </div>
                <div class="receipt-preview" data-receipt-preview hidden>
                    <div class="receipt-preview-header"><strong>Selected receipt</strong><button class="button ghost" type="button" data-remove-receipt>Remove</button></div>
                    <img data-receipt-preview-image alt="Selected receipt preview" hidden>
                    <div class="receipt-preview-file" data-receipt-preview-file hidden></div>
                    <div class="receipt-preview-meta" data-receipt-preview-meta></div>
                    <div class="receipt-quality-warning" data-receipt-quality-warning role="status" hidden></div>
                    <div class="receipt-preview-actions"><button class="button ghost" type="button" data-rotate="-90">Rotate left</button><button class="button ghost" type="button" data-rotate="90">Rotate right</button></div>
                </div>
                <div class="form-message" data-upload-message role="alert" hidden></div>
            </div>

            <div data-analysis-panel="text" hidden>
                <label>
                    <span>Paste receipt text</span>
                    <textarea name="receipt_text" rows="9" placeholder="Example: milk 2&#10;bread 1&#10;apples 4" disabled></textarea>
                    <small>Use this when you do not have a receipt image. The same analysis and review flow applies.</small>
                </label>
            </div>

            <label class="inline-option">
                <input type="checkbox" name="review_items" value="1" checked>
                <span>Review detected items before final analysis</span>
            </label>

            <label class="receipt-context-box">
                <span>Health note for this receipt</span>
                <textarea name="health_notes" rows="4" placeholder="Example: 2 months pregnant, this receipt is only for one person, buying for a child, low-salt diet, diabetic patient, gym diet, medicine restrictions..."><?= e($healthProfile['health_notes'] ?? '') ?></textarea>
                <small>Words like pregnant, diabetic, child, only one person, or low salt make the advice more personal.</small>
            </label>

            <p class="privacy-note">Your receipt is processed for nutrition analysis. Review detected items before relying on the health score.</p>
            <div class="form-message" data-form-message role="alert" hidden></div>
            <div class="analysis-submit-row"><button class="button primary" type="submit">Analyze receipt</button><span class="muted" data-submit-status aria-live="polite">Ready to analyze</span></div>
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

<?php if ($recentResults): ?>
<section class="panel recent-analyses">
    <div class="section-heading"><div><h2>Recent Analyses</h2><p class="muted">Quick access to your latest results.</p></div><a class="table-link" href="history.php">View history</a></div>
    <div class="recent-analysis-list">
        <?php foreach ($recentResults as $recent): ?>
            <a href="dashboard.php?id=<?= e($recent['_id']) ?>"><strong><?= e($recent['receipt_metadata']['store_name'] ?? ($recent['receipt_asset']['original_name'] ?? 'Receipt')) ?></strong><span><?= e(date('M d, Y', $recent['_created_at'])) ?> · <?= e($recent['health_score']['label'] ?? 'Completed') ?></span><b>View</b></a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

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
