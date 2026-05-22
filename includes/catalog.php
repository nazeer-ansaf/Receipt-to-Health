<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function food_catalog(): array
{
    $path = DATA_DIR . DIRECTORY_SEPARATOR . 'food_catalog.json';

    if (!is_file($path)) {
        return [];
    }

    $catalog = json_decode((string)file_get_contents($path), true);
    return is_array($catalog) ? $catalog : [];
}

function food_catalog_categories(): array
{
    $categories = [];

    foreach (food_catalog() as $food) {
        $category = (string)($food['category'] ?? 'unknown');
        $categories[$category] = ($categories[$category] ?? 0) + 1;
    }

    ksort($categories);
    return $categories;
}

