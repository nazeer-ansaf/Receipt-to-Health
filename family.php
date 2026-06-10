<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/profile.php';
require_once __DIR__ . '/includes/results.php';

$profilePath = DATA_DIR . DIRECTORY_SEPARATOR . 'family_profile.json';
$message = '';
$healthProfile = load_user_health_profile();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensure_directory(DATA_DIR);
    $profile = [
        'household_name' => trim((string)($_POST['household_name'] ?? 'My Household')),
        'family_size' => max(1, min(20, (int)($_POST['family_size'] ?? 1))),
        'age_group' => preg_replace('/[^a-zA-Z_-]/', '', (string)($_POST['age_group'] ?? 'mixed')),
        'conditions' => array_values(array_filter((array)($_POST['conditions'] ?? []))),
        'updated_at' => date('c'),
    ];
    file_put_contents($profilePath, json_encode($profile, JSON_PRETTY_PRINT));
    $message = 'Family profile saved for project demonstration.';
}

$profile = is_file($profilePath)
    ? json_decode((string)file_get_contents($profilePath), true)
    : [
        'household_name' => 'My Household',
        'family_size' => 4,
        'age_group' => 'mixed',
        'conditions' => ['diabetes'],
    ];

if (!is_array($profile)) {
    $profile = ['household_name' => 'My Household', 'family_size' => 4, 'age_group' => 'mixed', 'conditions' => []];
}

$conditions = $profile['conditions'] ?? [];

render_page_start('Family Profile', 'family');
page_hero(
    'Personalization layer',
    'Family Profile and Risk Weighting',
    'This module captures household composition so receipt quantities can be normalized per person and weighted by health context.'
);
?>

<?php if ($message !== ''): ?>
    <section class="notice"><?= e($message) ?></section>
<?php endif; ?>

<section class="grid dashboard-grid">
    <article class="panel span-7">
        <h2>Household Configuration</h2>
        <form method="post">
            <label>
                <span>Household name</span>
                <input type="text" name="household_name" value="<?= e($profile['household_name'] ?? '') ?>" required>
            </label>

            <div class="grid two">
                <label>
                    <span>Family members</span>
                    <input type="number" name="family_size" min="1" max="20" value="<?= e($profile['family_size'] ?? 4) ?>" required>
                </label>
                <label>
                    <span>Age group</span>
                    <select name="age_group">
                        <?php foreach (['adult' => 'Adults', 'children' => 'Children', 'elderly' => 'Elderly', 'mixed' => 'Mixed family'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($profile['age_group'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <fieldset>
                <legend>Health conditions</legend>
                <div class="chips">
                    <?php foreach (['diabetes' => 'Diabetes risk', 'hypertension' => 'Hypertension', 'cholesterol' => 'High cholesterol'] as $value => $label): ?>
                        <label>
                            <input type="checkbox" name="conditions[]" value="<?= e($value) ?>" <?= in_array($value, $conditions, true) ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <button class="button primary" type="submit">Save family profile</button>
        </form>
    </article>

    <aside class="panel span-5">
        <h2>Weighting Model</h2>
        <div class="weight-list">
            <div><strong>Diabetes</strong><span>Increases sugar weight by 10 points</span></div>
            <div><strong>Hypertension</strong><span>Increases sodium weight by 10 points</span></div>
            <div><strong>Cholesterol</strong><span>Increases saturated fat weight by 8 points</span></div>
            <div><strong>Elderly</strong><span>Adds extra sodium and fat sensitivity</span></div>
            <div><strong>Children</strong><span>Adds extra sugar sensitivity</span></div>
        </div>
    </aside>
</section>

<section class="grid three">
    <article class="panel feature-card">
        <span class="feature-number">1</span>
        <h2>Per-Person Normalization</h2>
        <p>Total nutrient load from the receipt is divided by household size.</p>
    </article>
    <article class="panel feature-card">
        <span class="feature-number">2</span>
        <h2>Condition Weighting</h2>
        <p>Risk conditions shift the scoring algorithm toward the most relevant nutrient dangers.</p>
    </article>
    <article class="panel feature-card">
        <span class="feature-number">3</span>
        <h2>Personal Advice</h2>
        <p>Recommendations mention the condition when a risky item affects a specific family profile.</p>
    </article>
</section>

<section class="panel">
    <h2>Detailed Family Member Profiles</h2>
    <?php if (empty($healthProfile['family_members'])): ?>
        <p class="muted">Add named family members on the Health Profile page to personalize reports more deeply.</p>
    <?php else: ?>
        <div class="family-member-mini-grid">
            <?php foreach ($healthProfile['family_members'] as $member): ?>
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
    <p class="muted"><a class="table-link" href="profile_setup.php">Edit detailed family member profiles</a></p>
</section>

<?php render_page_end(); ?>
