import argparse
import csv
import datetime as dt
import hashlib
import json
import os
import pickle
import random
from collections import Counter

from ml_food_classifier import FoodItemClassifier
from process_receipt import FOOD_GRAPH, ML_MODEL_PATH


ROOT_DIR = os.path.dirname(os.path.dirname(__file__))
DEFAULT_DATASET = os.path.join(ROOT_DIR, "data", "training_food_items.csv")
DEFAULT_CATALOG = os.path.join(ROOT_DIR, "data", "food_catalog.json")
MODEL_INFO_PATH = os.path.join(os.path.dirname(ML_MODEL_PATH), "food_classifier_model.json")
NUMERIC_FIELDS = ["sugar_g", "saturated_fat_g", "sodium_mg", "fiber_g"]
TEXT_FIELDS = ["category", "risk", "recommendation"]


def clean_text(value):
    if value is None:
        return ""

    return " ".join(str(value).strip().lower().split())


def clean_display_text(value):
    if value is None:
        return ""

    return " ".join(str(value).strip().split())


def parse_number(value):
    text = str(value or "").strip()

    if text == "":
        return None

    try:
        return float(text)
    except ValueError:
        return None


def list_values(value):
    if value is None:
        return []

    text = str(value or "").strip().lower()

    if text == "":
        return []

    values = []

    for chunk in text.replace("|", ",").replace(";", ",").split(","):
        clean_value = clean_text(chunk)

        if clean_value:
            values.append(clean_value)

    return values


def metadata_from_row(row, label):
    metadata = {"name": label}

    for field in TEXT_FIELDS:
        value = clean_display_text(row.get(field, ""))

        if value:
            metadata[field] = value.lower() if field != "recommendation" else value

    for field in NUMERIC_FIELDS:
        value = parse_number(row.get(field))

        if value is not None:
            metadata[field] = value

    aliases = list_values(row.get("aliases") or row.get("alias"))

    if aliases:
        metadata["aliases"] = aliases

    alternatives = list_values(row.get("alternatives") or row.get("alternative"))

    if alternatives:
        metadata["alternatives"] = alternatives

    return metadata


def has_useful_metadata(metadata):
    return any(field in metadata for field in [*TEXT_FIELDS, *NUMERIC_FIELDS, "aliases", "alternatives"])


def merge_metadata(existing, incoming):
    merged = dict(existing)

    for field in TEXT_FIELDS:
        if field in incoming:
            merged[field] = incoming[field]

    for field in NUMERIC_FIELDS:
        if field in incoming:
            merged[field] = incoming[field]

    for field in ["aliases", "alternatives"]:
        values = []

        if isinstance(existing.get(field), list):
            values.extend(existing[field])
        elif isinstance(existing.get(field), str):
            values.extend(list_values(existing[field]))

        if isinstance(incoming.get(field), list):
            values.extend(incoming[field])

        if values:
            merged[field] = sorted(set(clean_text(value) for value in values if clean_text(value)))

    return merged


def load_dataset(path):
    rows = []
    metadata_by_label = {}
    row_metadata = {}
    skipped_rows = []

    with open(path, "r", encoding="utf-8", newline="") as dataset_file:
        reader = csv.DictReader(dataset_file)

        for row in reader:
            receipt_line = clean_text(row.get("receipt_line", ""))
            label = clean_text(row.get("label", ""))
            metadata = metadata_from_row(row, label) if label else {}

            if has_useful_metadata(metadata):
                metadata_by_label[label] = merge_metadata(metadata_by_label.get(label, {}), metadata)
                row_metadata[receipt_line] = metadata

            if receipt_line and (label in FOOD_GRAPH or label in metadata_by_label):
                rows.append((receipt_line, label))
            elif receipt_line or label:
                skipped_rows.append({
                    "receipt_line": receipt_line,
                    "label": label,
                    "reason": "Label is not in FOOD_GRAPH and has no category/nutrition metadata.",
                })

    for label, food in FOOD_GRAPH.items():
        for alias in food.get("aliases", []):
            rows.append((clean_text(alias), label))
            rows.append((clean_text(f"{alias} 1"), label))

    unique_rows = sorted(set(rows))

    if not unique_rows:
        raise RuntimeError(f"No valid training rows found in {path}")

    return unique_rows, metadata_by_label, row_metadata, skipped_rows


def catalog_defaults(label):
    return {
        "name": label,
        "category": "other",
        "sugar_g": 0,
        "saturated_fat_g": 0,
        "sodium_mg": 0,
        "fiber_g": 0,
        "risk": "low risk",
        "recommendation": "Keep portions balanced.",
    }


def sync_food_catalog(metadata_by_label, catalog_path):
    if not metadata_by_label:
        return {"catalog_path": catalog_path, "updated_labels": [], "added_labels": []}

    if os.path.isfile(catalog_path):
        with open(catalog_path, "r", encoding="utf-8") as catalog_file:
            catalog = json.load(catalog_file)
    else:
        catalog = []

    if not isinstance(catalog, list):
        raise RuntimeError(f"Food catalog must be a JSON list: {catalog_path}")

    by_name = {
        clean_text(food.get("name", "")): dict(food)
        for food in catalog
        if isinstance(food, dict) and clean_text(food.get("name", ""))
    }
    added_labels = []
    updated_labels = []

    for label, metadata in sorted(metadata_by_label.items()):
        if not has_useful_metadata(metadata):
            continue

        existing = by_name.get(label)

        if existing:
            by_name[label] = merge_metadata(existing, metadata)
            updated_labels.append(label)
        else:
            by_name[label] = merge_metadata(catalog_defaults(label), metadata)
            added_labels.append(label)

    merged_catalog = [by_name[name] for name in sorted(by_name)]

    with open(catalog_path, "w", encoding="utf-8") as catalog_file:
        json.dump(merged_catalog, catalog_file, indent=2)
        catalog_file.write("\n")

    return {
        "catalog_path": catalog_path,
        "updated_labels": updated_labels,
        "added_labels": added_labels,
    }


def can_stratify(labels):
    counts = Counter(labels)
    return len(counts) > 1 and min(counts.values()) >= 2


def split_rows(rows, test_ratio=0.25):
    grouped = {}

    for row in rows:
        grouped.setdefault(row[1], []).append(row)

    train_rows = []
    test_rows = []
    random.seed(42)

    for label_rows in grouped.values():
        random.shuffle(label_rows)
        test_count = max(1, int(round(len(label_rows) * test_ratio))) if len(label_rows) > 2 else 1
        test_rows.extend(label_rows[:test_count])
        train_rows.extend(label_rows[test_count:])

    if not train_rows:
        return rows, []

    return train_rows, test_rows


def classification_report(labels, predictions):
    report = {}

    for label in sorted(set(labels)):
        true_positive = sum(1 for actual, predicted in zip(labels, predictions) if actual == label and predicted == label)
        false_positive = sum(1 for actual, predicted in zip(labels, predictions) if actual != label and predicted == label)
        false_negative = sum(1 for actual, predicted in zip(labels, predictions) if actual == label and predicted != label)
        precision = true_positive / (true_positive + false_positive) if true_positive + false_positive else 0.0
        recall = true_positive / (true_positive + false_negative) if true_positive + false_negative else 0.0
        f1_score = 2 * precision * recall / (precision + recall) if precision + recall else 0.0
        report[label] = {
            "precision": round(precision, 4),
            "recall": round(recall, 4),
            "f1_score": round(f1_score, 4),
        }

    return report


def accuracy_for(model, rows):
    if not rows:
        return 1.0, [], []

    labels = [row[1] for row in rows]
    predictions = model.predict([row[0] for row in rows])
    correct = sum(1 for actual, predicted in zip(labels, predictions) if actual == predicted)
    return correct / len(labels), labels, predictions


def category_for_label(label, metadata_by_label):
    category = clean_text(metadata_by_label.get(label, {}).get("category", ""))

    if category:
        return category

    return clean_text(FOOD_GRAPH.get(label, {}).get("category", "other")) or "other"


def dataset_hash(path):
    hash_object = hashlib.sha256()

    with open(path, "rb") as dataset_file:
        for chunk in iter(lambda: dataset_file.read(65536), b""):
            hash_object.update(chunk)

    return hash_object.hexdigest()


def train_model(rows, metadata_by_label):
    labels = [row[1] for row in rows]
    train_rows, test_rows = split_rows(rows)

    item_model = FoodItemClassifier()
    item_model.fit(train_rows)
    accuracy, y_test, predictions = accuracy_for(item_model, test_rows)

    category_rows = [
        (text, category_for_label(label, metadata_by_label))
        for text, label in rows
    ]
    category_train_rows, category_test_rows = split_rows(category_rows)
    category_model = FoodItemClassifier()
    category_model.fit(category_train_rows)
    category_accuracy, category_y_test, category_predictions = accuracy_for(category_model, category_test_rows)

    metrics = {
        "accuracy": round(float(accuracy), 4),
        "category_accuracy": round(float(category_accuracy), 4),
        "training_rows": len(train_rows),
        "test_rows": len(test_rows),
        "labels": sorted(set(labels)),
        "classification_report": classification_report(y_test, predictions),
        "category_labels": sorted(set(row[1] for row in category_rows)),
        "category_classification_report": classification_report(category_y_test, category_predictions),
    }

    item_model.fit(rows)
    category_model.fit(category_rows)
    return item_model, category_model, metrics


def main():
    parser = argparse.ArgumentParser(description="Train the receipt food item ML classifier.")
    parser.add_argument("--dataset", default=DEFAULT_DATASET)
    parser.add_argument("--output", default=ML_MODEL_PATH)
    parser.add_argument("--metrics", default=os.path.join(os.path.dirname(ML_MODEL_PATH), "food_classifier_metrics.json"))
    parser.add_argument("--model-info", default=MODEL_INFO_PATH)
    parser.add_argument("--catalog", default=DEFAULT_CATALOG)
    parser.add_argument("--no-sync-catalog", action="store_true", help="Train only; do not merge category/nutrition columns into food_catalog.json.")
    args = parser.parse_args()

    rows, metadata_by_label, row_metadata, skipped_rows = load_dataset(args.dataset)
    catalog_sync = {"catalog_path": args.catalog, "updated_labels": [], "added_labels": []}

    if not args.no_sync_catalog:
        catalog_sync = sync_food_catalog(metadata_by_label, args.catalog)

    item_model, category_model, metrics = train_model(rows, metadata_by_label)
    trained_at = dt.datetime.now(dt.timezone.utc).isoformat()
    model_version = {
        "version": trained_at.replace(":", "").replace("+00:00", "Z"),
        "trained_at": trained_at,
        "dataset": args.dataset,
        "dataset_hash": dataset_hash(args.dataset),
        "dataset_rows": len(rows),
        "label_count": len(metrics["labels"]),
        "category_count": len(metrics["category_labels"]),
    }
    model_bundle = {
        "item_classifier": item_model,
        "category_classifier": category_model,
        "model_version": model_version,
        "metadata_by_label": metadata_by_label,
    }
    metrics["dataset"] = args.dataset
    metrics["model_version"] = model_version
    metrics["row_metadata_count"] = len(row_metadata)
    metrics["skipped_rows"] = skipped_rows
    metrics["catalog_sync"] = catalog_sync

    os.makedirs(os.path.dirname(args.output), exist_ok=True)
    with open(args.output, "wb") as model_file:
        pickle.dump(model_bundle, model_file)

    with open(args.metrics, "w", encoding="utf-8") as metrics_file:
        json.dump(metrics, metrics_file, indent=2)

    with open(args.model_info, "w", encoding="utf-8") as model_info_file:
        json.dump(model_version, model_info_file, indent=2)

    print(json.dumps({
        "status": "trained",
        "model_path": args.output,
        "metrics_path": args.metrics,
        "model_info_path": args.model_info,
        "version": model_version["version"],
        "accuracy": metrics["accuracy"],
        "category_accuracy": metrics["category_accuracy"],
        "training_rows": metrics["training_rows"],
        "test_rows": metrics["test_rows"],
        "catalog_added": catalog_sync["added_labels"],
        "catalog_updated": catalog_sync["updated_labels"],
        "skipped_rows": len(skipped_rows),
    }))


if __name__ == "__main__":
    main()
