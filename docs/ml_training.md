# ML Training Guide

## Purpose

The project uses a trainable character n-gram Naive Bayes model to classify receipt lines into standard food labels. If an item cannot be matched by catalog aliases, the ML model predicts the food label. A second model predicts the food category for unmatched lines so the dashboard can still show useful evidence.

## Dataset Format

Required columns:

```csv
receipt_line,label
coca cola bottle,soda
pringles family pack,chips
anchor fresh milk,milk
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

## Admin Workflow

1. Log in as admin.
2. Open `admin.php`.
3. Use **ML Dataset and Model**.
4. Upload a CSV with `receipt_line` and `label`.
5. Optional category and nutrition columns are merged into `data/food_catalog.json`.
6. Review accuracy, model version, and skipped rows.

## Evidence For Viva

Show these points:

- Rule matching is tried first for explainability.
- ML classification runs only when alias matching fails.
- Each detected item stores `detection_method`.
- Dashboard displays item confidence and whether the item came from rules or ML.
- Corrected user items are appended back into the training dataset.
- The model is versioned with dataset hash, row count, label count, and training time.
