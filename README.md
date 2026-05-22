# Receipt-to-Health

AI-powered household nutritional intelligence from grocery receipts.

## Current System

This project includes a final-year-project style web system:

1. Receipt upload and household context capture.
2. PHP upload handler with MySQL persistence.
3. Python AI pipeline for OCR placeholder, NLP normalization, scoring, anomaly detection, and recommendations.
4. Advanced dashboard with seven-layer AI evidence.
5. Score breakdown, nutrition risk matrix, item table, extracted text, and trend snapshot.
6. Receipt history and score timeline.
7. Analytics center with moving averages, category dominance, and recurring recommendations.
8. OCR review and correction workflow.
9. Food database and nutrient dictionary.
10. Nutrition knowledge graph page.
11. What-if scoring simulator.
12. Family profile and risk-weighting page.
13. Printable reports plus JSON/CSV export.
14. Admin evidence console for database and generated files.
15. Register/login/logout account module with password hashing.
16. Methodology page with architecture, formulas, database scope, and risk awareness.
17. Setup check page for local environment validation.

## Local Setup

1. Start XAMPP Apache and MySQL.
2. Create the database:

```sql
CREATE DATABASE receipt_to_health;
```

3. Import:

```txt
database/schema.sql
```

4. Check the setup:

```txt
http://localhost/receipt_to_health/setup_check.php
```

5. Open the app:

```txt
http://localhost/receipt_to_health/
```

## Python Setup

Python must be available in PATH:

```txt
python --version
```

Install OCR/ML libraries when connecting real OCR:

```txt
pip install -r python/requirements.txt
```

## Architecture

```txt
OCR -> NLP -> Knowledge Graph -> Scoring -> Trend -> Anomaly -> Recommendation
```

## Next Development Steps

1. Add real OCR using Tesseract or EasyOCR.
2. Improve NLP normalization with fuzzy matching.
3. Expand the food knowledge graph.
4. Connect saved family profile defaults to uploads.
5. Build user login and role-based access.
6. Use MySQL history for trend and anomaly baselines.
7. Add manual OCR correction before final analysis.
8. Add downloadable PDF reports.
9. Add role-based authentication for admin/user separation.
10. Train a stronger item matching model from real receipt examples.
