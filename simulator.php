<?php
require_once __DIR__ . '/includes/layout.php';

render_page_start('What-If Simulator', 'simulator');
page_hero(
    'Decision support',
    'Nutrition What-If Simulator',
    'A live scoring simulator for demonstrating how sugar, fat, sodium, fiber, diversity, and family health conditions affect the final household health score.'
);
?>

<section class="grid dashboard-grid simulator" id="simulator">
    <article class="panel span-7">
        <h2>Scenario Inputs</h2>
        <div class="sim-grid">
            <label><span>Sugar per person (g)</span><input data-sim="sugar" type="range" min="0" max="100" value="45"><b data-out="sugar">45</b></label>
            <label><span>Saturated fat per person (g)</span><input data-sim="fat" type="range" min="0" max="35" value="12"><b data-out="fat">12</b></label>
            <label><span>Sodium per person (mg)</span><input data-sim="sodium" type="range" min="0" max="2500" value="850"><b data-out="sodium">850</b></label>
            <label><span>Fiber per person (g)</span><input data-sim="fiber" type="range" min="0" max="20" value="8"><b data-out="fiber">8</b></label>
            <label><span>Nutrient diversity</span><input data-sim="diversity" type="range" min="1" max="12" value="5"><b data-out="diversity">5</b></label>
        </div>

        <fieldset>
            <legend>Condition weighting</legend>
            <div class="chips">
                <label><input data-condition="diabetes" type="checkbox"> Diabetes</label>
                <label><input data-condition="hypertension" type="checkbox"> Hypertension</label>
                <label><input data-condition="cholesterol" type="checkbox"> Cholesterol</label>
                <label><input data-condition="children" type="checkbox"> Children</label>
                <label><input data-condition="elderly" type="checkbox"> Elderly</label>
            </div>
        </fieldset>
    </article>

    <aside class="panel span-5">
        <h2>Simulated Result</h2>
        <div class="sim-score">
            <span>Health score</span>
            <strong data-sim-score>0</strong>
            <small data-sim-label>Calculating</small>
        </div>
        <div class="breakdown-bars compact-bars">
            <div class="bar-row"><span>Sugar</span><div class="bar-track"><i data-bar="sugar"></i></div><strong data-component="sugar">0</strong></div>
            <div class="bar-row"><span>Fat</span><div class="bar-track"><i data-bar="fat"></i></div><strong data-component="fat">0</strong></div>
            <div class="bar-row"><span>Sodium</span><div class="bar-track"><i data-bar="sodium"></i></div><strong data-component="sodium">0</strong></div>
            <div class="bar-row"><span>Fiber</span><div class="bar-track"><i data-bar="fiber"></i></div><strong data-component="fiber">0</strong></div>
            <div class="bar-row"><span>Diversity</span><div class="bar-track"><i data-bar="diversity"></i></div><strong data-component="diversity">0</strong></div>
        </div>
    </aside>
</section>

<section class="panel">
    <h2>How to Use in Viva</h2>
    <div class="method-steps">
        <div><strong>Show personalization</strong><p>Turn on Diabetes and watch sugar weight reduce the final score.</p></div>
        <div><strong>Show risk trade-off</strong><p>Raise vegetables/fiber and diversity, then show that high sugar can still dominate risk.</p></div>
        <div><strong>Show model transparency</strong><p>Each component score is visible, so the algorithm is explainable.</p></div>
        <div><strong>Show decision support</strong><p>Use it to compare shopping choices before actual purchase behavior changes.</p></div>
    </div>
</section>

<?php render_page_end(); ?>

