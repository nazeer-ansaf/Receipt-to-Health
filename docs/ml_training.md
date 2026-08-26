# ML Training Guide

## Purpose

The project uses a trainable character n-gram Naive Bayes model to classify receipt lines into standard food labels. If an item cannot be matched by catalog aliases, the ML model predicts the food label. A second model predicts the food category for unmatched lines so the dashboard can still show useful evidence.

The CSV is the raw labelled evidence source. Catalog aliases and quantity variants are generated in memory during training and are not permanently appended to the CSV. Nutrition, risk, recommendations, aliases, and alternatives remain canonical in `data/food_catalog.json` and the runtime knowledge graph.

## Dataset Format

Required columns:

```csv
receipt_line,label,source,variant_group
coca cola bottle,soda,manual,soda:coca-cola
pringles family pack,chips,manual,chips:pringles
anchor fresh milk,milk,manual,milk:anchor
```

Recommended full format:

```csv
receipt_line,label,category,sugar_g,saturated_fat_g,sodium_mg,fiber_g,risk,recommendation,aliases,alternatives
coca cola bottle,soda,sugary drink,39,0,45,0,high sugar,Replace soda with water.,cola|soft drink,water|unsweetened tea
```

## Training Command

Run from the project root:

```powershell
cd C:\xampp\htdocs\receipt-to-health
python python\train_food_model.py
```

Generate the controlled 5k-stage corpus first:

```powershell
python python\generate_training_variants.py
python python\train_food_model.py
```

The generator writes `data/generated_training_variants.csv` as a separate, reproducible source containing `synthetic` and `ocr_augmented` examples. It does not modify the raw labelled CSV.

Custom dataset:

```powershell
python python\train_food_model.py --dataset data\your_dataset.csv
```

## Outputs

The training script creates:

```text
python/models/food_classifier.joblib
python/models/food_classifier_metrics.json
python/models/food_classifier_model.json
```

The app reads `food_classifier.joblib` automatically during receipt analysis.

Training records raw-row counts, effective examples, provenance counts, group-aware train/validation/test counts, dataset and catalog hashes, trainer schema version, macro metrics, confusion reports, and unknown-item rejection results. The default split uses seed `42` and approximately 70% train, 15% validation, and 15% test; examples sharing a `variant_group` never cross splits.

## Admin Workflow

1. Log in as admin.
2. Open `admin.php`.
3. Use **ML Dataset and Model**.
4. Upload a CSV with `receipt_line` and `label`.
5. Optional category and nutrition columns are merged into `data/food_catalog.json`.
6. Review current/stale status, raw/effective counts, split counts, macro F1, hashes, and skipped/conflicting rows.

## Evidence For Viva

Show these points:

- Rule matching is tried first for explainability.
- ML classification runs only when alias matching fails.
- Each detected item stores `detection_method`.
- Dashboard displays item confidence and whether the item came from rules or ML.
- Corrected user items are appended as `user_feedback` only when they are not duplicates or conflicting mappings.
- The model is versioned with dataset/catalog hashes, split counts, group count, label count, category count, trainer schema, and training time.

User corrections are written to `data/training_candidates.csv` and remain pending until administrator verification. No real-world untouched holdout dataset currently exists in the repository; synthetic examples must not be presented as real holdout evidence.

## Real receipt holdout

`data/real_receipt_holdout.csv` is reserved for genuine receipt evidence and is intentionally empty until real, verified examples are collected. It is evaluation-only: the trainer, catalog alias generation, augmentation generator, and feedback candidate path do not read it. The Admin panel validates the required fields, imports the file without retraining, and runs `python/evaluate_real_holdout.py` separately. That evaluator checks exact and strong near-duplicate contamination against approved training, generated variants, and pending candidates before reporting metrics.

User corrections are quarantined in `data/training_candidates.csv`. Admin approval promotes a candidate into the approved CSV with `source=user_feedback` and its `variant_group` preserved; rejection is recorded in `data/training_candidates_rejected.csv` and never enters training.

Receipt analysis now reports `score_coverage` and `detection_layers`, including scored food lines, unmatched lines, deterministic/rule/ML resolution, and a warning when the health score may be incomplete. This is pipeline evidence, not a substitute for verified real-receipt ground truth.

## Controlled expansion checkpoint

The controlled experiment selected the approximately 7,546-example checkpoint: 137 manual rows, 292 catalog aliases, 3,744 synthetic variants, and 3,373 OCR-augmented variants. Its grouped test results were item accuracy 0.6143, macro F1 0.6503, weighted F1 0.6240, category accuracy 0.5926, and category macro F1 0.5780. Larger checkpoints were not selected because they reduced item macro F1: approximately 8,735 rows reached 0.5772, approximately 9,920 reached 0.5881, and approximately 10,010 reached 0.5822. The expanded 20-example unknown suite reports 0.10 false acceptance for every tested checkpoint, exposing two plausible unseen food/brand phrases that the current evidence layer accepts; this is reported rather than hidden.
