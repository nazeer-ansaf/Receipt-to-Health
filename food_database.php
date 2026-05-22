<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/catalog.php';

$catalog = food_catalog();
$categories = food_catalog_categories();

render_page_start('Food Database', 'foods');
page_hero(
    'Nutrition data layer',
    'Food Database and Nutrient Dictionary',
    'This module exposes the structured food catalog used by the knowledge graph and scoring engine.'
);
?>

<section class="score-band">
    <article class="metric"><span>Food records</span><strong><?= count($catalog) ?></strong><small>catalog entries</small></article>
    <article class="metric"><span>Categories</span><strong><?= count($categories) ?></strong><small>food groups</small></article>
    <article class="metric"><span>Risk links</span><strong><?= count($catalog) ?></strong><small>mapped items</small></article>
    <article class="metric"><span>Advice rules</span><strong><?= count($catalog) ?></strong><small>recommendations</small></article>
</section>

<section class="grid two">
    <article class="panel">
        <h2>Category Coverage</h2>
        <div class="category-grid">
            <?php foreach ($categories as $category => $count): ?>
                <div><span><?= e(ucwords($category)) ?></span><strong><?= e($count) ?></strong></div>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="panel">
        <h2>Knowledge Graph Role</h2>
        <div class="module-list">
            <div><strong>Food node</strong><span>Standard normalized item name.</span></div>
            <div><strong>Nutrient node</strong><span>Sugar, fat, sodium, fiber, and diversity values.</span></div>
            <div><strong>Risk node</strong><span>Health interpretation from nutrient pattern.</span></div>
            <div><strong>Recommendation node</strong><span>Explainable action generated for the user.</span></div>
        </div>
    </article>
</section>

<section class="panel">
    <h2>Food Catalog Records</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Food</th>
                    <th>Category</th>
                    <th>Sugar</th>
                    <th>Sat Fat</th>
                    <th>Sodium</th>
                    <th>Fiber</th>
                    <th>Risk</th>
                    <th>Recommendation</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($catalog as $food): ?>
                    <tr>
                        <td><?= e($food['name'] ?? '') ?></td>
                        <td><?= e($food['category'] ?? '') ?></td>
                        <td><?= e($food['sugar_g'] ?? 0) ?>g</td>
                        <td><?= e($food['saturated_fat_g'] ?? 0) ?>g</td>
                        <td><?= e($food['sodium_mg'] ?? 0) ?>mg</td>
                        <td><?= e($food['fiber_g'] ?? 0) ?>g</td>
                        <td><?= e($food['risk'] ?? '') ?></td>
                        <td><?= e($food['recommendation'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_page_end(); ?>

