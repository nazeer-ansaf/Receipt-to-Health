<?php
require_once __DIR__ . '/includes/layout.php';

$foods = [
    'Milk' => ['Dairy', 'Sugar', 'Saturated Fat', 'Moderate dairy sugar', 'Choose low-fat unsweetened milk.'],
    'Bread' => ['Grain', 'Sodium', 'Low fiber', 'Refined carbohydrate', 'Prefer whole-grain bread.'],
    'Soda' => ['Sugary Drink', 'Added Sugar', 'Diabetes Risk', 'High sugar', 'Replace with water or unsweetened tea.'],
    'Chips' => ['Snack', 'Sodium', 'Hypertension Risk', 'High sodium snack', 'Limit salty packaged snacks.'],
    'Apple' => ['Fruit', 'Fiber', 'Micronutrients', 'Low risk', 'Keep fruit as a regular purchase.'],
    'Rice' => ['Grain', 'Carbohydrate', 'Low fiber grain', 'Portion risk', 'Balance with vegetables and protein.'],
    'Vegetables' => ['Vegetable', 'Fiber', 'Nutrient Density', 'Low risk', 'Increase variety and frequency.'],
];

render_page_start('Knowledge Graph', 'graph');
page_hero(
    'Nutrition knowledge graph',
    'Food to Nutrient to Risk Mapping',
    'The knowledge graph is the rule base that connects receipt items with nutrients, health risks, and explainable recommendations.'
);
?>

<section class="graph-board">
    <article class="graph-column">
        <h2>Food Items</h2>
        <?php foreach (array_keys($foods) as $food): ?>
            <div class="graph-node food"><?= e($food) ?></div>
        <?php endforeach; ?>
    </article>
    <article class="graph-column">
        <h2>Nutrients</h2>
        <div class="graph-node nutrient">Sugar</div>
        <div class="graph-node nutrient">Sodium</div>
        <div class="graph-node nutrient">Saturated Fat</div>
        <div class="graph-node nutrient">Fiber</div>
        <div class="graph-node nutrient">Diversity</div>
    </article>
    <article class="graph-column">
        <h2>Health Risks</h2>
        <div class="graph-node risk">Diabetes Risk</div>
        <div class="graph-node risk">Hypertension Risk</div>
        <div class="graph-node risk">Cholesterol Risk</div>
        <div class="graph-node risk">Low Fiber Pattern</div>
        <div class="graph-node risk">Poor Diversity</div>
    </article>
    <article class="graph-column">
        <h2>Advice</h2>
        <div class="graph-node advice">Reduce sugary drinks</div>
        <div class="graph-node advice">Choose low sodium snacks</div>
        <div class="graph-node advice">Add vegetables</div>
        <div class="graph-node advice">Increase whole grains</div>
        <div class="graph-node advice">Improve variety</div>
    </article>
</section>

<section class="panel">
    <h2>Knowledge Graph Records</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Food</th>
                    <th>Category</th>
                    <th>Nutrient Link</th>
                    <th>Risk Link</th>
                    <th>Recommendation</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($foods as $food => $row): ?>
                    <tr>
                        <td><?= e($food) ?></td>
                        <td><?= e($row[0]) ?></td>
                        <td><?= e($row[1]) ?>, <?= e($row[2]) ?></td>
                        <td><?= e($row[3]) ?></td>
                        <td><?= e($row[4]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_page_end(); ?>

