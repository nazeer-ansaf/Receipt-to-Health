<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function food_catalog_path(): string
{
    return DATA_DIR . DIRECTORY_SEPARATOR . 'food_catalog.json';
}

function food_catalog(): array
{
    $path = food_catalog_path();

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

function catalog_list_from_text(string $value): array
{
    return array_values(array_filter(array_map(
        static fn($item) => trim((string)$item),
        preg_split('/[\r\n,]+/', $value) ?: []
    )));
}

function normalize_catalog_record(array $payload): array
{
    $name = strtolower(trim((string)($payload['name'] ?? '')));
    $name = preg_replace('/\s+/', ' ', $name) ?: '';

    return [
        'name' => $name,
        'category' => trim((string)($payload['category'] ?? 'other')) ?: 'other',
        'sugar_g' => max(0, (float)($payload['sugar_g'] ?? 0)),
        'saturated_fat_g' => max(0, (float)($payload['saturated_fat_g'] ?? 0)),
        'sodium_mg' => max(0, (float)($payload['sodium_mg'] ?? 0)),
        'fiber_g' => max(0, (float)($payload['fiber_g'] ?? 0)),
        'risk' => trim((string)($payload['risk'] ?? 'low risk')) ?: 'low risk',
        'recommendation' => trim((string)($payload['recommendation'] ?? 'Keep portions balanced.')) ?: 'Keep portions balanced.',
        'aliases' => catalog_list_from_text((string)($payload['aliases'] ?? $name)),
        'alternatives' => catalog_list_from_text((string)($payload['alternatives'] ?? '')),
    ];
}

function save_food_catalog(array $catalog): void
{
    $normalized = [];
    $seen = [];

    foreach ($catalog as $record) {
        if (!is_array($record)) {
            continue;
        }

        $item = normalize_catalog_record($record);

        if ($item['name'] === '' || isset($seen[$item['name']])) {
            continue;
        }

        $seen[$item['name']] = true;
        $normalized[] = $item;
    }

    usort($normalized, static fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
    file_put_contents(food_catalog_path(), json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function upsert_food_catalog_item(array $payload, string $originalName = ''): void
{
    $catalog = food_catalog();
    $record = normalize_catalog_record($payload);

    if ($record['name'] === '') {
        throw new InvalidArgumentException('Food name is required.');
    }

    $originalName = strtolower(trim($originalName));
    $updated = false;

    foreach ($catalog as $index => $item) {
        $name = strtolower((string)($item['name'] ?? ''));

        if ($name === $originalName || $name === $record['name']) {
            $catalog[$index] = $record;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $catalog[] = $record;
    }

    save_food_catalog($catalog);
}

function delete_food_catalog_item(string $name): void
{
    $name = strtolower(trim($name));
    $catalog = array_values(array_filter(food_catalog(), static function ($item) use ($name): bool {
        return strtolower((string)($item['name'] ?? '')) !== $name;
    }));

    save_food_catalog($catalog);
}
