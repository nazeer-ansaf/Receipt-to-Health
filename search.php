<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/results.php';
require_once __DIR__ . '/includes/catalog.php';

$query = trim((string)($_GET['q'] ?? ''));
$needle = strtolower($query);
$matches = [];

function add_match(array &$matches, string $type, string $title, string $detail, string $href): void
{
    $matches[] = [
        'type' => $type,
        'title' => $title,
        'detail' => $detail,
        'href' => $href,
    ];
}

function contains_query(string $haystack, string $needle): bool
{
    return $needle !== '' && str_contains(strtolower($haystack), $needle);
}

if ($needle !== '') {
    foreach (nav_items() as $item) {
        if (contains_query($item['label'], $needle) || contains_query($item['href'], $needle)) {
            add_match($matches, 'Module', $item['label'], 'Application module', $item['href']);
        }
    }

    foreach (food_catalog() as $food) {
        $searchText = implode(' ', array_map('strval', $food));
        if (contains_query($searchText, $needle)) {
            add_match(
                $matches,
                'Food',
                (string)($food['name'] ?? 'Food item'),
                (string)($food['category'] ?? '') . ' - ' . (string)($food['risk'] ?? ''),
                'food_database.php'
            );
        }
    }

    foreach (load_all_results() as $result) {
        $reportText = json_encode($result);
        if (!is_string($reportText) || !contains_query($reportText, $needle)) {
            continue;
        }

        add_match(
            $matches,
            'Report',
            'Report ' . (string)$result['_id'],
            'Score ' . score_value($result) . ', ' . result_item_count($result) . ' items',
            'dashboard.php?id=' . urlencode((string)$result['_id'])
        );
    }
}

render_page_start('Search', 'search');
page_hero(
    'Global search',
    'Search System Knowledge',
    'Find modules, foods, risks, recommendations, and stored receipt reports from one place.'
);
?>

<section class="panel">
    <form class="search-page-form" action="search.php" method="get">
        <label>
            <span>Search query</span>
            <input type="search" name="q" value="<?= e($query) ?>" placeholder="Try sugar, soda, report, anomaly, OCR">
        </label>
        <button class="button primary" type="submit">Search</button>
    </form>
</section>

<section class="panel">
    <h2>Results</h2>
    <?php if ($query === ''): ?>
        <p class="muted">Enter a search term to search modules, food records, and reports.</p>
    <?php elseif (!$matches): ?>
        <p>No matches found for <strong><?= e($query) ?></strong>.</p>
    <?php else: ?>
        <div class="search-results">
            <?php foreach ($matches as $match): ?>
                <a href="<?= e($match['href']) ?>">
                    <span><?= e($match['type']) ?></span>
                    <strong><?= e($match['title']) ?></strong>
                    <small><?= e($match['detail']) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php render_page_end(); ?>

