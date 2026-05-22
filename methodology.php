<?php
require_once __DIR__ . '/includes/layout.php';

render_page_start('Methodology', 'method');
page_hero(
    'Research and implementation depth',
    'System Methodology',
    'A final-year project should clearly show architecture, formulas, assumptions, risk handling, and implementation boundaries.'
);
?>

<section class="panel">
    <h2>Seven-Layer AI Architecture</h2>
    <div class="method-steps">
        <div><strong>1. OCR Extraction</strong><p>Receipt images are converted into raw text using the OCR layer. The current demo supports text receipts and is prepared for Tesseract/EasyOCR.</p></div>
        <div><strong>2. NLP Normalization</strong><p>Raw item names are cleaned, tokenized, matched to aliases, and normalized into standard food names.</p></div>
        <div><strong>3. Nutrition Knowledge Graph</strong><p>Normalized foods map to food category, nutrients, risks, and recommendation rules.</p></div>
        <div><strong>4. Health Scoring</strong><p>Sugar, saturated fat, sodium, fiber, and diversity are converted into bounded component scores and combined by weights.</p></div>
        <div><strong>5. Time-Series Trends</strong><p>Each receipt becomes a historical point for weekly and monthly household behavior analysis.</p></div>
        <div><strong>6. Statistical Anomaly Detection</strong><p>Unusual purchase quantities are flagged using the Z-score formula.</p></div>
        <div><strong>7. Recommendation Engine</strong><p>Advice is generated from nutrient thresholds, risk conditions, item categories, and anomalies.</p></div>
    </div>
</section>

<section class="grid two">
    <article class="panel">
        <h2>Scoring Formula</h2>
        <p>The final health score is a weighted average of component scores:</p>
        <pre class="formula">Score = Σ(component_score × weight) / Σ(weight)</pre>
        <p>Risk conditions adjust weights. For example, diabetes increases sugar sensitivity, while hypertension increases sodium sensitivity.</p>
    </article>

    <article class="panel">
        <h2>Z-Score Formula</h2>
        <p>Statistical anomaly detection compares the current quantity with baseline behavior:</p>
        <pre class="formula">Z = (X - μ) / σ</pre>
        <p>If the absolute Z-score is high, the system flags the item as unusual.</p>
    </article>
</section>

<section class="panel">
    <h2>Database and Implementation Scope</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Database Tables</th>
                    <th>Purpose</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>User and family</td><td>users, family_profiles, family_members</td><td>Store household context and health conditions.</td></tr>
                <tr><td>Receipt processing</td><td>receipts, receipt_items</td><td>Store uploaded receipt and normalized purchases.</td></tr>
                <tr><td>Knowledge graph</td><td>food_items, nutrition_data, health_risks</td><td>Map foods to nutrients, risks, and recommendations.</td></tr>
                <tr><td>Analytics</td><td>health_scores, trend_history, anomalies</td><td>Store scores, historical patterns, and unusual purchases.</td></tr>
                <tr><td>Recommendation</td><td>recommendations</td><td>Store generated advice and explanations.</td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>Application Modules</h2>
    <div class="method-steps">
        <div><strong>Upload Workspace</strong><p>Collects family context and receipt evidence before analysis.</p></div>
        <div><strong>Dashboard</strong><p>Shows seven-layer AI evidence, score breakdown, risks, anomalies, and recommendations.</p></div>
        <div><strong>Analytics Center</strong><p>Aggregates receipt history into trends, moving averages, category dominance, and recurring advice.</p></div>
        <div><strong>OCR Review</strong><p>Supports human correction of OCR text before NLP and scoring.</p></div>
        <div><strong>Food Database</strong><p>Displays the nutrition dictionary used by the knowledge graph.</p></div>
        <div><strong>Simulator</strong><p>Demonstrates how model weights and nutrient values affect the health score.</p></div>
        <div><strong>Account Module</strong><p>Shows registration, login, session handling, password hashing, and user-linked receipt storage.</p></div>
        <div><strong>Reports</strong><p>Provides printable output and JSON/CSV export for submission evidence.</p></div>
        <div><strong>Admin Console</strong><p>Shows database counts, generated result files, and implementation status.</p></div>
    </div>
</section>

<section class="panel">
    <h2>Risk Awareness</h2>
    <div class="risk-cards">
        <div><strong>OCR errors</strong><span>Use preprocessing, manual correction, and multi-OCR comparison.</span></div>
        <div><strong>NLP mismatch</strong><span>Use fuzzy matching, aliases, and user confirmation.</span></div>
        <div><strong>Incomplete receipts</strong><span>Warn the user and allow manual item addition.</span></div>
        <div><strong>Privacy</strong><span>Store minimal personal data and avoid unnecessary sensitive details.</span></div>
        <div><strong>Medical limits</strong><span>Present guidance as dietary awareness, not medical diagnosis.</span></div>
    </div>
</section>

<?php render_page_end(); ?>
