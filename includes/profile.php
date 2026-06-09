<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

function profile_data_dir(): string
{
    return DATA_DIR . DIRECTORY_SEPARATOR . 'profiles';
}

function profile_storage_key(?array $user = null): string
{
    $user = $user ?? current_user();

    if ($user && empty($user['is_guest'])) {
        return 'user_' . (int)$user['id'];
    }

    start_app_session();
    $sessionKey = preg_replace('/[^a-zA-Z0-9_-]/', '', session_id()) ?: 'guest';
    return 'guest_' . $sessionKey;
}

function profile_path(?array $user = null): string
{
    return profile_data_dir() . DIRECTORY_SEPARATOR . profile_storage_key($user) . '.json';
}

function default_health_profile(?array $user = null): array
{
    $user = $user ?? current_user();

    return [
        'display_name' => $user['name'] ?? 'Guest Visitor',
        'household_name' => 'My Household',
        'family_size' => 4,
        'age_group' => 'mixed',
        'activity_level' => 'moderate',
        'diet_goal' => 'balanced',
        'conditions' => [],
        'allergies' => '',
        'medications' => '',
        'preferred_foods' => '',
        'avoid_foods' => '',
        'health_notes' => '',
        'analysis' => [],
        'updated_at' => null,
    ];
}

function load_user_health_profile(?array $user = null): array
{
    $profile = default_health_profile($user);
    $path = profile_path($user);

    if (!is_file($path)) {
        return $profile;
    }

    $stored = json_decode((string)file_get_contents($path), true);
    return is_array($stored) ? array_replace($profile, $stored) : $profile;
}

function save_user_health_profile(array $profile, ?array $user = null): void
{
    ensure_directory(profile_data_dir());
    $profile['analysis'] = generate_health_profile_analysis($profile);
    $profile['updated_at'] = date('c');
    file_put_contents(profile_path($user), json_encode($profile, JSON_PRETTY_PRINT));
}

function sanitize_profile_payload(array $payload): array
{
    $conditions = $payload['conditions'] ?? [];

    if (!is_array($conditions)) {
        $conditions = [];
    }

    return [
        'display_name' => trim((string)($payload['display_name'] ?? '')),
        'household_name' => trim((string)($payload['household_name'] ?? 'My Household')),
        'family_size' => max(1, min(20, (int)($payload['family_size'] ?? 1))),
        'age_group' => preg_replace('/[^a-zA-Z_-]/', '', (string)($payload['age_group'] ?? 'mixed')) ?: 'mixed',
        'activity_level' => preg_replace('/[^a-zA-Z_-]/', '', (string)($payload['activity_level'] ?? 'moderate')) ?: 'moderate',
        'diet_goal' => preg_replace('/[^a-zA-Z_-]/', '', (string)($payload['diet_goal'] ?? 'balanced')) ?: 'balanced',
        'conditions' => sanitize_profile_conditions($conditions),
        'allergies' => trim((string)($payload['allergies'] ?? '')),
        'medications' => trim((string)($payload['medications'] ?? '')),
        'preferred_foods' => trim((string)($payload['preferred_foods'] ?? '')),
        'avoid_foods' => trim((string)($payload['avoid_foods'] ?? '')),
        'health_notes' => trim((string)($payload['health_notes'] ?? '')),
    ];
}

function sanitize_profile_conditions(array $conditions): array
{
    return array_values(array_filter(array_map(
        static fn($value) => preg_replace('/[^a-zA-Z_-]/', '', (string)$value),
        $conditions
    )));
}

function profile_completion_score(array $profile): int
{
    $fields = ['display_name', 'household_name', 'family_size', 'age_group', 'activity_level', 'diet_goal', 'health_notes'];
    $completed = 0;

    foreach ($fields as $field) {
        if (isset($profile[$field]) && trim((string)$profile[$field]) !== '') {
            $completed++;
        }
    }

    if (!empty($profile['conditions'])) {
        $completed++;
    }

    return (int)round(($completed / 8) * 100);
}

function generate_health_profile_analysis(array $profile): array
{
    $conditions = array_map('strtolower', $profile['conditions'] ?? []);
    $notes = strtolower((string)($profile['health_notes'] ?? ''));
    $ageGroup = strtolower((string)($profile['age_group'] ?? 'mixed'));
    $dietGoal = strtolower((string)($profile['diet_goal'] ?? 'balanced'));
    $activityLevel = strtolower((string)($profile['activity_level'] ?? 'moderate'));

    $focus = [];
    $recommendations = [];

    if (in_array('diabetes', $conditions, true) || str_contains($notes, 'diabetes') || str_contains($notes, 'sugar')) {
        $focus['Sugar'] = 'Prioritize low added-sugar foods and pair carbohydrates with fiber or protein.';
        $recommendations[] = 'Keep sweet drinks, desserts, and highly refined snacks as occasional purchases.';
    }

    if (in_array('hypertension', $conditions, true) || str_contains($notes, 'pressure') || str_contains($notes, 'salt')) {
        $focus['Sodium'] = 'Watch packaged sauces, instant foods, processed meats, and salty snacks.';
        $recommendations[] = 'Choose lower-sodium alternatives and add more fresh fruit, vegetables, and legumes.';
    }

    if (in_array('cholesterol', $conditions, true) || str_contains($notes, 'cholesterol') || str_contains($notes, 'fat')) {
        $focus['Saturated fat'] = 'Limit fried foods, full-fat dairy, fatty meats, and rich bakery items.';
        $recommendations[] = 'Shift repeated purchases toward oats, beans, nuts, fish, and unsaturated cooking oils.';
    }

    if ($ageGroup === 'children') {
        $focus['Child nutrition'] = 'Balance snacks with protein, fruit, and calcium-rich foods.';
        $recommendations[] = 'Build lunchbox-friendly options around whole grains, eggs, yogurt, fruit, and vegetables.';
    }

    if ($ageGroup === 'elderly') {
        $focus['Senior support'] = 'Track sodium, protein quality, hydration, and easy-to-prepare nutrient-dense foods.';
        $recommendations[] = 'Prefer soft high-protein foods, soups with controlled salt, and fiber-rich staples.';
    }

    if ($dietGoal === 'weight_loss') {
        $focus['Energy balance'] = 'Favor high-fiber foods, lean proteins, and smaller portions of energy-dense snacks.';
    } elseif ($dietGoal === 'muscle_gain') {
        $focus['Protein quality'] = 'Add lean protein and nutrient-dense carbohydrate sources across weekly shopping.';
    } elseif ($dietGoal === 'heart_health') {
        $focus['Heart health'] = 'Keep saturated fat and sodium low while increasing fiber-rich foods.';
    }

    if ($activityLevel === 'low') {
        $recommendations[] = 'Use receipt analysis to reduce calorie-dense snack repetition and increase easy-prep whole foods.';
    } elseif ($activityLevel === 'high') {
        $recommendations[] = 'Include enough complex carbohydrates, hydration options, and lean protein for higher activity days.';
    }

    if (trim((string)($profile['allergies'] ?? '')) !== '') {
        $focus['Allergy awareness'] = 'Review ingredient labels carefully for listed allergy or intolerance notes.';
    }

    if (!$focus) {
        $focus['Balanced nutrition'] = 'Monitor sugar, sodium, saturated fat, fiber, and food diversity together.';
        $recommendations[] = 'Use each receipt report to build a more balanced weekly shopping pattern.';
    }

    return [
        'completion_score' => profile_completion_score($profile),
        'summary' => sprintf(
            '%s is set up as a %s household with %d member(s), a %s activity level, and a %s nutrition goal.',
            $profile['household_name'] ?? 'This profile',
            str_replace('_', ' ', $ageGroup),
            (int)($profile['family_size'] ?? 1),
            str_replace('_', ' ', $activityLevel),
            str_replace('_', ' ', $dietGoal)
        ),
        'focus' => $focus,
        'recommendations' => array_values(array_unique($recommendations)),
    ];
}
