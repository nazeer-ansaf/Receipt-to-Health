<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/profile.php';
require_once __DIR__ . '/includes/medical_records.php';

if (!has_app_access()) {
    header('Location: login.php');
    exit;
}

$user = current_user();
$saved = false;
$profile = load_user_health_profile($user);
$recordStatus = (string)($_GET['record_status'] ?? '');
$recordError = trim((string)($_GET['record_error'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile = array_replace($profile, sanitize_profile_payload($_POST));
    save_user_health_profile($profile, $user);
    $profile = load_user_health_profile($user);
    $saved = true;
}

$analysis = $profile['analysis'] ?: generate_health_profile_analysis($profile);
$conditions = $profile['conditions'] ?? [];
$medicalRecords = load_medical_records($user);

$ageGroups = [
    'adult' => 'Adults',
    'children' => 'Children',
    'elderly' => 'Elderly',
    'mixed' => 'Mixed family',
];

$activityLevels = [
    'low' => 'Low activity',
    'moderate' => 'Moderate activity',
    'high' => 'High activity',
];

$dietGoals = [
    'balanced' => 'Balanced nutrition',
    'weight_loss' => 'Weight loss',
    'muscle_gain' => 'Muscle gain',
    'heart_health' => 'Heart health',
];

$conditionOptions = [
    'diabetes' => 'Diabetes risk',
    'hypertension' => 'Hypertension',
    'cholesterol' => 'High cholesterol',
    'kidney' => 'Kidney concern',
    'allergy' => 'Food allergy',
];

render_page_start('Health Profile', 'profile');
page_hero(
    'First step',
    'Create Your Health Profile',
    'Add household details and health context before receipt analysis so reports can be personalized from the beginning.',
    '<a class="button ghost" href="index.php">Skip to receipt upload</a>'
);
?>

<?php if ($saved): ?>
    <section class="notice">Health profile saved. Receipt analysis will now use this context.</section>
<?php endif; ?>

<?php if ($recordStatus === 'uploaded'): ?>
    <section class="notice">Medical record uploaded and attached to this health profile.</section>
<?php endif; ?>

<?php if ($recordError !== ''): ?>
    <section class="warning-text"><?= e($recordError) ?></section>
<?php endif; ?>

<section class="score-band">
    <article class="metric">
        <span>Access mode</span>
        <strong><?= e(ucfirst((string)($user['role'] ?? 'user'))) ?></strong>
        <small><?= e($user['auth_provider'] ?? 'local') ?> login</small>
    </article>
    <article class="metric">
        <span>Profile strength</span>
        <strong><?= e($analysis['completion_score'] ?? 0) ?>%</strong>
        <small>details captured</small>
    </article>
    <article class="metric">
        <span>Household size</span>
        <strong><?= e($profile['family_size'] ?? 1) ?></strong>
        <small><?= e($ageGroups[$profile['age_group'] ?? 'mixed'] ?? 'Mixed family') ?></small>
    </article>
    <article class="metric">
        <span>Medical records</span>
        <strong><?= e(count($medicalRecords)) ?></strong>
        <small>profile files uploaded</small>
    </article>
</section>

<section class="grid dashboard-grid">
    <article class="panel span-7">
        <h2>Profile Details</h2>
        <form method="post" class="profile-form">
            <div class="grid two">
                <label>
                    <span>Your name</span>
                    <input type="text" name="display_name" value="<?= e($profile['display_name'] ?? '') ?>" required>
                </label>
                <label>
                    <span>Household name</span>
                    <input type="text" name="household_name" value="<?= e($profile['household_name'] ?? '') ?>" required>
                </label>
            </div>

            <div class="grid two">
                <label>
                    <span>Family members</span>
                    <input type="number" name="family_size" min="1" max="20" value="<?= e($profile['family_size'] ?? 4) ?>" required>
                </label>
                <label>
                    <span>Age group</span>
                    <select name="age_group">
                        <?php foreach ($ageGroups as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($profile['age_group'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="grid two">
                <label>
                    <span>Activity level</span>
                    <select name="activity_level">
                        <?php foreach ($activityLevels as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($profile['activity_level'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Nutrition goal</span>
                    <select name="diet_goal">
                        <?php foreach ($dietGoals as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($profile['diet_goal'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <fieldset>
                <legend>Health conditions</legend>
                <div class="chips">
                    <?php foreach ($conditionOptions as $value => $label): ?>
                        <label>
                            <input type="checkbox" name="conditions[]" value="<?= e($value) ?>" <?= in_array($value, $conditions, true) ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="grid two">
                <label>
                    <span>Allergies or intolerances</span>
                    <input type="text" name="allergies" value="<?= e($profile['allergies'] ?? '') ?>" placeholder="Example: peanuts, lactose">
                </label>
                <label>
                    <span>Medicines or restrictions</span>
                    <input type="text" name="medications" value="<?= e($profile['medications'] ?? '') ?>" placeholder="Example: low salt plan">
                </label>
            </div>

            <div class="grid two">
                <label>
                    <span>Preferred foods</span>
                    <input type="text" name="preferred_foods" value="<?= e($profile['preferred_foods'] ?? '') ?>" placeholder="Example: rice, fish, vegetables">
                </label>
                <label>
                    <span>Foods to avoid</span>
                    <input type="text" name="avoid_foods" value="<?= e($profile['avoid_foods'] ?? '') ?>" placeholder="Example: soda, fried snacks">
                </label>
            </div>

            <label>
                <span>Tell the AI anything health related</span>
                <textarea name="health_notes" rows="7" placeholder="Write symptoms, family health goals, doctor advice, food preferences, shopping habits, or concerns."><?= e($profile['health_notes'] ?? '') ?></textarea>
            </label>

            <section class="embedded-form-section">
                <h2>Family Member Profiles</h2>
                <div class="family-member-editor">
                    <?php
                        $memberRows = $profile['family_members'] ?? [];
                        for ($index = 0; $index < 4; $index++):
                            $member = is_array($memberRows[$index] ?? null) ? $memberRows[$index] : ['name' => '', 'age_group' => 'adult', 'conditions' => [], 'notes' => ''];
                            $memberConditions = is_array($member['conditions'] ?? null) ? $member['conditions'] : [];
                    ?>
                        <article class="family-member-card">
                            <div class="grid two">
                                <label>
                                    <span>Member name</span>
                                    <input type="text" name="member_name[]" value="<?= e($member['name'] ?? '') ?>" placeholder="Example: mother, father, child">
                                </label>
                                <label>
                                    <span>Age group</span>
                                    <select name="member_age_group[]">
                                        <?php foreach ($ageGroups as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= ($member['age_group'] ?? 'adult') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>

                            <fieldset>
                                <legend>Member conditions</legend>
                                <div class="chips compact-chips">
                                    <?php foreach ($conditionOptions as $value => $label): ?>
                                        <label>
                                            <input type="checkbox" name="member_conditions[<?= e($index) ?>][]" value="<?= e($value) ?>" <?= in_array($value, $memberConditions, true) ? 'checked' : '' ?>>
                                            <?= e($label) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <label>
                                <span>Member note</span>
                                <input type="text" name="member_notes[]" value="<?= e($member['notes'] ?? '') ?>" placeholder="Example: pregnant, low salt, gym diet">
                            </label>
                        </article>
                    <?php endfor; ?>
                </div>
            </section>

            <div class="form-actions">
                <button class="button primary" type="submit">Save and analyze profile</button>
                <a class="button ghost" href="index.php">Continue to receipt analysis</a>
            </div>
        </form>
    </article>

    <aside class="panel span-5">
        <h2>AI Health Analysis</h2>
        <p class="lede small-lede"><?= e($analysis['summary'] ?? '') ?></p>

        <div class="module-list compact-list">
            <?php foreach (($analysis['focus'] ?? []) as $label => $detail): ?>
                <div>
                    <strong><?= e($label) ?></strong>
                    <span><?= e($detail) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </aside>
</section>

<section id="medical-records" class="grid dashboard-grid">
    <article class="panel span-5">
        <h2>Medical Records</h2>
        <form class="medical-record-form" action="api/upload_medical_record.php" method="post" enctype="multipart/form-data">
            <label>
                <span>Record title</span>
                <input type="text" name="title" maxlength="160" placeholder="Example: blood report, prescription, discharge summary">
            </label>

            <label class="upload-box medical-upload-box" data-empty-label="No medical record selected">
                <span>PDF, image, or text record</span>
                <input type="file" name="medical_record" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt" required>
                <small>Upload doctor notes, lab reports, prescriptions, or discharge summaries up to 10 MB.</small>
            </label>

            <label>
                <span>Important notes from this record</span>
                <textarea name="notes" rows="4" maxlength="1000" placeholder="Example: HbA1c is high, doctor recommended low sodium diet, avoid peanuts..."></textarea>
            </label>

            <button class="button primary" type="submit">Upload medical record</button>
        </form>
    </article>

    <article class="panel span-7">
        <h2>Uploaded Record History</h2>
        <?php if (empty($medicalRecords)): ?>
            <p class="muted">No medical records are attached to this profile yet.</p>
        <?php else: ?>
            <div class="medical-record-list">
                <?php foreach ($medicalRecords as $record): ?>
                    <?php
                        $uploadedAt = strtotime((string)($record['uploaded_at'] ?? '')) ?: time();
                        $recordTitle = trim((string)($record['title'] ?? '')) ?: (string)($record['original_name'] ?? 'Medical record');
                        $recordNotes = trim((string)($record['notes'] ?? ''));
                    ?>
                    <article class="medical-record-card">
                        <div class="medical-record-main">
                            <strong><?= e($recordTitle) ?></strong>
                            <span><?= e($record['original_name'] ?? 'Uploaded file') ?></span>
                            <small>
                                <?= e(date('M d, Y H:i', $uploadedAt)) ?>
                                &middot;
                                <?= e(strtoupper((string)($record['extension'] ?? 'file'))) ?>
                                &middot;
                                <?= e(format_medical_record_size((int)($record['size_bytes'] ?? 0))) ?>
                            </small>
                            <?php if ($recordNotes !== ''): ?>
                                <p><?= e($recordNotes) ?></p>
                            <?php endif; ?>
                        </div>
                        <a class="button ghost" href="api/download_medical_record.php?id=<?= e($record['id'] ?? '') ?>">Download</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="grid two">
    <article class="panel">
        <h2>Best Profile Direction</h2>
        <ul class="insight-list">
            <?php foreach (($analysis['recommendations'] ?? []) as $recommendation): ?>
                <li><?= e($recommendation) ?></li>
            <?php endforeach; ?>
            <?php if (empty($analysis['recommendations'])): ?>
                <li>Keep adding receipt data so recommendations become more specific.</li>
            <?php endif; ?>
        </ul>
    </article>

    <article class="panel">
        <h2>Project Details</h2>
        <div class="module-list">
            <div><strong>Receipt AI</strong><span>OCR, NLP cleanup, food mapping, scoring, and recommendations.</span></div>
            <div><strong>User roles</strong><span>Admin, user, and guest sessions are handled separately.</span></div>
            <div><strong>Profile context</strong><span>Your health notes are attached to future receipt reports.</span></div>
            <div><strong>Medical records</strong><span>Uploaded PDFs, images, and text records stay attached to this profile.</span></div>
            <div><strong>Guest mode</strong><span>Try the app without creating a stored account.</span></div>
        </div>
    </article>
</section>

<?php render_page_end(); ?>
