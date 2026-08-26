import argparse
import csv
import datetime as dt
import hashlib
import json
import os
import pickle
import random
import re
from collections import Counter, defaultdict
from difflib import SequenceMatcher

from ml_food_classifier import FoodItemClassifier
from process_receipt import FOOD_GRAPH, ML_MODEL_PATH, ml_prediction_has_evidence

ROOT_DIR = os.path.dirname(os.path.dirname(__file__))
DEFAULT_DATASET = os.path.join(ROOT_DIR, "data", "training_food_items.csv")
DEFAULT_GENERATED_DATASET = os.path.join(ROOT_DIR, "data", "generated_training_variants.csv")
DEFAULT_CATALOG = os.path.join(ROOT_DIR, "data", "food_catalog.json")
MODEL_INFO_PATH = os.path.join(os.path.dirname(ML_MODEL_PATH), "food_classifier_model.json")
TRAINER_SCHEMA_VERSION = "grouped-evaluation-v2"
SPLIT_SEED = 42
SPLIT_RATIOS = {"train": 0.70, "validation": 0.15, "test": 0.15}
NUMERIC_FIELDS = ["sugar_g", "saturated_fat_g", "sodium_mg", "fiber_g"]
TEXT_FIELDS = ["category", "risk", "recommendation"]
VARIANT_NOISE = {"bottle", "pack", "packet", "bag", "box", "tin", "can", "carton", "bunch", "loaf", "pot", "block", "tub", "jar", "cup", "pcs", "pc"}


def clean_text(value):
    return " ".join(str(value or "").strip().lower().split())


def clean_display_text(value):
    return " ".join(str(value or "").strip().split())


def parse_number(value):
    text = str(value or "").strip()
    if not text:
        return None
    try:
        return float(text)
    except ValueError:
        return None


def list_values(value):
    return [part for part in re.split(r"[,|;]+", clean_text(value)) if part]


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
    alternatives = list_values(row.get("alternatives") or row.get("alternative"))
    if aliases:
        metadata["aliases"] = aliases
    if alternatives:
        metadata["alternatives"] = alternatives
    return metadata


def has_useful_metadata(metadata):
    return any(field in metadata for field in [*TEXT_FIELDS, *NUMERIC_FIELDS, "aliases", "alternatives"])


def merge_metadata(existing, incoming):
    merged = dict(existing)
    for field in TEXT_FIELDS + NUMERIC_FIELDS:
        if field in incoming:
            merged[field] = incoming[field]
    for field in ["aliases", "alternatives"]:
        values = []
        values.extend(existing.get(field, []) if isinstance(existing.get(field), list) else list_values(existing.get(field)))
        values.extend(incoming.get(field, []) if isinstance(incoming.get(field), list) else list_values(incoming.get(field)))
        if values:
            merged[field] = sorted(set(clean_text(value) for value in values if clean_text(value)))
    return merged


def canonical_text(value):
    text = clean_text(value)
    text = re.sub(r"\b\d+(?:[.,]\d+)?\s*(?:kg|g|mg|l|ml|oz|lb|lbs|pcs|pc|ct|cl|pk|pack|packs)\b", " ", text)
    text = re.sub(r"\b\d+(?:[.,]\d+)?\b", " ", text)
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return " ".join(text.split())


def variant_signature(value):
    tokens = []
    for token in canonical_text(value).split():
        if token in VARIANT_NOISE:
            continue
        if token.endswith("ies") and len(token) > 4:
            token = token[:-3] + "y"
        elif token.endswith("s") and len(token) > 3:
            token = token[:-1]
        tokens.append(token)
    return " ".join(tokens)


def make_example(text, label, source, variant_group, augmentation=""):
    return {
        "text": clean_text(text),
        "label": clean_text(label),
        "source": clean_text(source) or "manual",
        "variant_group": clean_text(variant_group),
        "augmentation": clean_text(augmentation),
    }


def catalog_variant_groups():
    mapping = {}
    for label, food in FOOD_GRAPH.items():
        aliases = sorted(set([label, *food.get("aliases", [])]))
        signatures = sorted(set(variant_signature(alias) for alias in aliases))
        parent = {signature: signature for signature in signatures}

        def find(signature):
            while parent[signature] != signature:
                parent[signature] = parent[parent[signature]]
                signature = parent[signature]
            return signature

        def union(left, right):
            left_root = find(left)
            right_root = find(right)
            if left_root != right_root:
                parent[max(left_root, right_root)] = min(left_root, right_root)

        for left_index, left in enumerate(signatures):
            for right in signatures[left_index + 1:]:
                ratio = SequenceMatcher(None, left, right).ratio()
                if left in right or right in left or ratio >= 0.9:
                    union(left, right)

        for signature in signatures:
            root = find(signature)
            mapping[(signature, label)] = f"catalog:{label}:{root}"
    return mapping


def load_dataset(path, generated_path=None):
    examples = []
    metadata_by_label = {}
    row_metadata = {}
    skipped_rows = []
    conflicts = []
    duplicate_rows = []
    normalized_labels = defaultdict(set)
    seen = set()
    physical_rows = 0
    valid_raw_examples = 0
    generated_source_counts = Counter()
    catalog_group_by_key = catalog_variant_groups()
    catalog_aliases_by_label = defaultdict(list)
    for (alias_key, graph_label), group in catalog_group_by_key.items():
        catalog_aliases_by_label[graph_label].append((alias_key, group))

    with open(path, "r", encoding="utf-8", newline="") as dataset_file:
        for row in csv.DictReader(dataset_file):
            physical_rows += 1
            receipt_line = clean_text(row.get("receipt_line", ""))
            label = clean_text(row.get("label", ""))
            if not receipt_line or not label:
                skipped_rows.append({"receipt_line": receipt_line, "label": label, "reason": "Missing receipt_line or label."})
                continue

            metadata = metadata_from_row(row, label)
            if has_useful_metadata(metadata):
                metadata_by_label[label] = merge_metadata(metadata_by_label.get(label, {}), metadata)
                row_metadata[receipt_line] = metadata

            if label not in FOOD_GRAPH and label not in metadata_by_label:
                skipped_rows.append({"receipt_line": receipt_line, "label": label, "reason": "Label is not in FOOD_GRAPH and has no metadata."})
                continue

            normalized_line = canonical_text(receipt_line)
            group_key = variant_signature(receipt_line)
            if normalized_labels[normalized_line] and label not in normalized_labels[normalized_line]:
                conflicts.append({"receipt_line": receipt_line, "label": label, "normalized_line": normalized_line, "labels": sorted(normalized_labels[normalized_line] | {label})})
                continue
            normalized_labels[normalized_line].add(label)

            source = clean_text(row.get("source", "")) or "manual"
            variant_group = clean_text(row.get("variant_group", ""))
            if not variant_group:
                variant_group = catalog_group_by_key.get((group_key, label), f"{source}:{label}:{group_key}")
                if variant_group.startswith(f"{source}:{label}:"):
                    candidates = catalog_aliases_by_label.get(label, [])
                    matches = [
                        (SequenceMatcher(None, group_key, alias_key).ratio(), group)
                        for alias_key, group in candidates
                        if group_key == alias_key
                        or (len(alias_key.split()) > 1 and alias_key in group_key)
                        or (len(group_key.split()) > 1 and group_key in alias_key)
                        or SequenceMatcher(None, group_key, alias_key).ratio() >= 0.9
                    ]
                    if matches:
                        variant_group = max(matches, key=lambda item: (item[0], item[1]))[1]
            key = (receipt_line, label, source, variant_group)
            if key in seen:
                duplicate_rows.append({"receipt_line": receipt_line, "label": label, "reason": "Exact duplicate example."})
                continue
            seen.add(key)
            examples.append(make_example(receipt_line, label, source, variant_group))
            valid_raw_examples += 1

    if generated_path is None:
        generated_path = DEFAULT_GENERATED_DATASET if os.path.abspath(path) == os.path.abspath(DEFAULT_DATASET) else ""
    if generated_path and os.path.isfile(generated_path):
        with open(generated_path, "r", encoding="utf-8", newline="") as generated_file:
            for row in csv.DictReader(generated_file):
                receipt_line = clean_text(row.get("receipt_line", ""))
                label = clean_text(row.get("label", ""))
                source = clean_text(row.get("source", ""))
                variant_group = clean_text(row.get("variant_group", ""))
                if not receipt_line or not label or source not in {"synthetic", "ocr_augmented", "external"} or not variant_group:
                    skipped_rows.append({"receipt_line": receipt_line, "label": label, "reason": "Generated row requires receipt_line, label, source, and variant_group."})
                    continue
                normalized_line = canonical_text(receipt_line)
                key = (receipt_line, label, source, variant_group)
                if key in seen:
                    duplicate_rows.append({"receipt_line": receipt_line, "label": label, "reason": "Exact generated duplicate example."})
                    continue
                if normalized_labels[normalized_line] and label not in normalized_labels[normalized_line]:
                    conflicts.append({"receipt_line": receipt_line, "label": label, "normalized_line": normalized_line, "labels": sorted(normalized_labels[normalized_line] | {label})})
                    continue
                seen.add(key)
                normalized_labels[normalized_line].add(label)
                examples.append(make_example(receipt_line, label, source, variant_group, row.get("augmentation", "")))
                generated_source_counts[source] += 1

    generated_seen = set()
    catalog_derived_examples = 0
    for label, food in FOOD_GRAPH.items():
        for alias in sorted(set([label, *food.get("aliases", [])])):
            for text, augmentation in [(alias, "alias"), (f"{alias} 1", "quantity_variant")]:
                example = make_example(text, label, "catalog_alias", catalog_group_by_key[(variant_signature(alias), label)], augmentation)
                key = (example["text"], example["label"])
                if key in seen or key in generated_seen:
                    continue
                generated_seen.add(key)
                examples.append(example)
                catalog_derived_examples += 1

    if not examples:
        raise RuntimeError(f"No valid training rows found in {path}")

    return examples, metadata_by_label, row_metadata, skipped_rows, {
        "physical_csv_rows": physical_rows,
        "valid_raw_examples": valid_raw_examples,
        "catalog_derived_examples": catalog_derived_examples,
        "generated_augmentation_examples": catalog_derived_examples,
        "generated_source_counts": dict(generated_source_counts),
        "duplicate_rows": duplicate_rows,
        "conflicts": conflicts,
    }


def catalog_defaults(label):
    return {"name": label, "category": "other", "sugar_g": 0, "saturated_fat_g": 0, "sodium_mg": 0, "fiber_g": 0, "risk": "low risk", "recommendation": "Keep portions balanced."}


def sync_food_catalog(metadata_by_label, catalog_path):
    if not metadata_by_label:
        return {"catalog_path": catalog_path, "updated_labels": [], "added_labels": []}
    catalog = []
    if os.path.isfile(catalog_path):
        with open(catalog_path, "r", encoding="utf-8") as catalog_file:
            catalog = json.load(catalog_file)
    if not isinstance(catalog, list):
        raise RuntimeError(f"Food catalog must be a JSON list: {catalog_path}")
    by_name = {clean_text(food.get("name", "")): dict(food) for food in catalog if isinstance(food, dict) and clean_text(food.get("name", ""))}
    added_labels = []
    updated_labels = []
    for label, metadata in sorted(metadata_by_label.items()):
        if not has_useful_metadata(metadata):
            continue
        if label in by_name:
            by_name[label] = merge_metadata(by_name[label], metadata)
            updated_labels.append(label)
        else:
            by_name[label] = merge_metadata(catalog_defaults(label), metadata)
            added_labels.append(label)
    with open(catalog_path, "w", encoding="utf-8") as catalog_file:
        json.dump([by_name[name] for name in sorted(by_name)], catalog_file, indent=2)
        catalog_file.write("\n")
    return {"catalog_path": catalog_path, "updated_labels": updated_labels, "added_labels": added_labels}


def file_hash(path):
    digest = hashlib.sha256()
    with open(path, "rb") as source:
        for chunk in iter(lambda: source.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


def category_for_label(label, metadata_by_label):
    return clean_text(metadata_by_label.get(label, {}).get("category", "")) or clean_text(FOOD_GRAPH.get(label, {}).get("category", "other")) or "other"


def split_groups(examples, seed=SPLIT_SEED):
    groups = defaultdict(list)
    for index, example in enumerate(examples):
        groups[example["variant_group"]].append(index)
    rng = random.Random(seed)
    group_names = list(groups)
    rng.shuffle(group_names)
    assignments = {}
    reserved = set()
    labels = sorted(set(example["label"] for example in examples))
    for label in labels:
        candidates = [group for group in group_names if group not in reserved and any(examples[index]["label"] == label for index in groups[group])]
        if candidates:
            chosen = min(candidates, key=lambda group: (len(groups[group]), group))
            reserved.add(chosen)
            assignments[chosen] = "train"

    targets = {name: len(examples) * ratio for name, ratio in SPLIT_RATIOS.items()}
    counts = Counter({"train": sum(len(groups[group]) for group in reserved), "validation": 0, "test": 0})
    for group in [group for group in group_names if group not in assignments]:
        choices = sorted(SPLIT_RATIOS, key=lambda name: (targets[name] - counts[name], -SPLIT_RATIOS[name]), reverse=True)
        chosen = choices[0]
        assignments[group] = chosen
        counts[chosen] += len(groups[group])

    split_indices = {"train": [], "validation": [], "test": []}
    for group, indices in groups.items():
        split_indices[assignments[group]].extend(indices)
    return split_indices, assignments


def classification_report(labels, predictions):
    report = {}
    for label in sorted(set(labels) | set(predictions)):
        tp = sum(1 for actual, predicted in zip(labels, predictions) if actual == label and predicted == label)
        fp = sum(1 for actual, predicted in zip(labels, predictions) if actual != label and predicted == label)
        fn = sum(1 for actual, predicted in zip(labels, predictions) if actual == label and predicted != label)
        support = sum(1 for actual in labels if actual == label)
        precision = tp / (tp + fp) if tp + fp else 0.0
        recall = tp / (tp + fn) if tp + fn else 0.0
        f1 = 2 * precision * recall / (precision + recall) if precision + recall else 0.0
        report[label] = {"precision": round(precision, 4), "recall": round(recall, 4), "f1_score": round(f1, 4), "support": support}
    return report


def aggregate_metrics(labels, predictions):
    report = classification_report(labels, predictions)
    active = [row for row in report.values() if row["support"] > 0]
    total = len(labels) or 1
    return {
        "accuracy": round(sum(actual == predicted for actual, predicted in zip(labels, predictions)) / total, 4),
        "macro_precision": round(sum(row["precision"] for row in active) / len(active), 4) if active else 1.0,
        "macro_recall": round(sum(row["recall"] for row in active) / len(active), 4) if active else 1.0,
        "macro_f1": round(sum(row["f1_score"] for row in active) / len(active), 4) if active else 1.0,
        "weighted_f1": round(sum(row["f1_score"] * row["support"] for row in active) / total, 4),
        "classification_report": report,
    }


UNKNOWN_EVALUATION_EXAMPLES = [
    "qxzv-not-a-food-98765 1", "dish soap 2", "toilet paper pack", "laptop charger 1",
    "garden gloves", "laundry detergent", "shampoo bottle", "bathroom tissue 4 pack",
    "dragon fruit imported box", "tofu tempeh block", "sparkling yerba mate drink",
    "unknown brand snack 1", "protein cookie mega max", "kimchi ramen premium",
    "blue valley oat drink", "household storage basket", "phone charger cable",
    "vitamin supplement bottle", "qxq lll 0o0 1", "brnd xz snack 999",
]


def evaluate_unknowns(model):
    results = []
    for text in UNKNOWN_EVALUATION_EXAMPLES:
        label, confidence = model.predict_one(text)
        accepted = bool(label in FOOD_GRAPH and confidence >= 0.7 and ml_prediction_has_evidence(text, label))
        results.append({"text": text, "predicted_label": label, "confidence": round(confidence, 4), "accepted": accepted})
    accepted = sum(row["accepted"] for row in results)
    return {"total": len(results), "accepted_unknowns": accepted, "rejected_unknowns": len(results) - accepted, "false_acceptance_rate": round(accepted / len(results), 4), "rejection_rate": round((len(results) - accepted) / len(results), 4), "examples": results}


def train_model(examples, metadata_by_label):
    split_indices, assignments = split_groups(examples)
    split_rows = {name: [examples[index] for index in indices] for name, indices in split_indices.items()}
    item_model = FoodItemClassifier().fit([(row["text"], row["label"]) for row in split_rows["train"]])
    item_metrics = {}
    for name in ["validation", "test"]:
        rows = split_rows[name]
        item_metrics[name] = aggregate_metrics([row["label"] for row in rows], item_model.predict([row["text"] for row in rows]))

    category_rows = [{**row, "category": category_for_label(row["label"], metadata_by_label)} for row in examples]
    category_model = FoodItemClassifier().fit([(category_rows[index]["text"], category_rows[index]["category"]) for index in split_indices["train"]])
    category_metrics = {}
    for name in ["validation", "test"]:
        rows = [category_rows[index] for index in split_indices[name]]
        category_metrics[name] = aggregate_metrics([row["category"] for row in rows], category_model.predict([row["text"] for row in rows]))

    test_metrics = item_metrics["test"]
    test_rows = split_rows["test"]
    source_metrics = {}
    for source in sorted(set(row["source"] for row in test_rows)):
        source_rows = [row for row in test_rows if row["source"] == source]
        source_predictions = item_model.predict([row["text"] for row in source_rows])
        source_metrics[source] = aggregate_metrics([row["label"] for row in source_rows], source_predictions)
    unknown_metrics = evaluate_unknowns(item_model)

    # Production models are fit on every effective example only after evaluation.
    item_model.fit([(row["text"], row["label"]) for row in examples])
    category_model.fit([(row["text"], row["category"]) for row in category_rows])
    return item_model, category_model, {
        "accuracy": test_metrics["accuracy"],
        "macro_precision": test_metrics["macro_precision"],
        "macro_recall": test_metrics["macro_recall"],
        "macro_f1": test_metrics["macro_f1"],
        "weighted_f1": test_metrics["weighted_f1"],
        "validation": item_metrics["validation"],
        "test": test_metrics,
        "category_accuracy": category_metrics["test"]["accuracy"],
        "category_validation": category_metrics["validation"],
        "category_test": category_metrics["test"],
        "labels": sorted(set(row["label"] for row in examples)),
        "category_labels": sorted(set(row["category"] for row in category_rows)),
        "group_count": len(set(row["variant_group"] for row in examples)),
        "split_counts": {name: len(rows) for name, rows in split_rows.items()},
        "split_group_counts": {name: len({examples[index]["variant_group"] for index in indices}) for name, indices in split_indices.items()},
        "split_assignments": assignments,
        "unknown_evaluation": unknown_metrics,
        "source_metrics": source_metrics,
        "label_counts": dict(Counter(row["label"] for row in examples)),
        "category_counts": dict(Counter(row["category"] for row in category_rows)),
        "source_label_counts": {source: dict(Counter(row["label"] for row in examples if row["source"] == source)) for source in sorted(set(row["source"] for row in examples))},
    }


def main():
    parser = argparse.ArgumentParser(description="Train the receipt food item ML classifier with grouped evaluation.")
    parser.add_argument("--dataset", default=DEFAULT_DATASET)
    parser.add_argument("--generated-dataset", default=DEFAULT_GENERATED_DATASET)
    parser.add_argument("--output", default=ML_MODEL_PATH)
    parser.add_argument("--metrics", default=os.path.join(os.path.dirname(ML_MODEL_PATH), "food_classifier_metrics.json"))
    parser.add_argument("--model-info", default=MODEL_INFO_PATH)
    parser.add_argument("--catalog", default=DEFAULT_CATALOG)
    parser.add_argument("--no-sync-catalog", action="store_true")
    args = parser.parse_args()

    examples, metadata_by_label, row_metadata, skipped_rows, report = load_dataset(args.dataset, args.generated_dataset)
    catalog_sync = {"catalog_path": args.catalog, "updated_labels": [], "added_labels": []}
    if not args.no_sync_catalog:
        catalog_sync = sync_food_catalog(metadata_by_label, args.catalog)
    item_model, category_model, metrics = train_model(examples, metadata_by_label)
    trained_at = dt.datetime.now(dt.timezone.utc).isoformat()
    raw_hash = file_hash(args.dataset)
    generated_hash = file_hash(args.generated_dataset) if os.path.isfile(args.generated_dataset) else ""
    catalog_hash = file_hash(args.catalog) if os.path.isfile(args.catalog) else ""
    model_version = {
        "version": trained_at.replace(":", "").replace("+00:00", "Z"),
        "trained_at": trained_at,
        "raw_dataset_hash": raw_hash,
        "dataset_hash": raw_hash,
        "catalog_hash": catalog_hash,
        "generated_dataset_hash": generated_hash,
        "trainer_schema_version": TRAINER_SCHEMA_VERSION,
        "split_seed": SPLIT_SEED,
        "physical_csv_rows": report["physical_csv_rows"],
        "valid_raw_examples": report["valid_raw_examples"],
        "catalog_derived_examples": report["catalog_derived_examples"],
        "generated_augmentation_examples": report["generated_augmentation_examples"] + sum(report["generated_source_counts"].values()),
        "effective_dataset_rows": len(examples),
        "dataset_rows": len(examples),
        "train_rows": metrics["split_counts"]["train"],
        "validation_rows": metrics["split_counts"]["validation"],
        "test_rows": metrics["split_counts"]["test"],
        "group_count": metrics["group_count"],
        "label_count": len(metrics["labels"]),
        "category_count": len(metrics["category_labels"]),
    }
    model_bundle = {"item_classifier": item_model, "category_classifier": category_model, "model_version": model_version, "metadata_by_label": metadata_by_label}
    metrics.update({
        "dataset": args.dataset,
        "model_version": model_version,
        "physical_csv_rows": report["physical_csv_rows"],
        "valid_raw_examples": report["valid_raw_examples"],
        "catalog_derived_examples": report["catalog_derived_examples"],
        "generated_augmentation_examples": report["generated_augmentation_examples"] + sum(report["generated_source_counts"].values()),
        "effective_dataset_rows": len(examples),
        "row_metadata_count": len(row_metadata),
        "source_counts": dict(Counter(row["source"] for row in examples)),
        "generated_source_counts": report["generated_source_counts"],
        "skipped_rows": skipped_rows,
        "duplicate_rows": report["duplicate_rows"],
        "conflicts": report["conflicts"],
        "catalog_sync": catalog_sync,
    })

    os.makedirs(os.path.dirname(args.output), exist_ok=True)
    with open(args.output, "wb") as model_file:
        pickle.dump(model_bundle, model_file)
    with open(args.metrics, "w", encoding="utf-8") as metrics_file:
        json.dump(metrics, metrics_file, indent=2)
    with open(args.model_info, "w", encoding="utf-8") as model_info_file:
        json.dump(model_version, model_info_file, indent=2)

    print(json.dumps({"status": "trained", "model_path": args.output, "metrics_path": args.metrics, "model_info_path": args.model_info, "version": model_version["version"], "accuracy": metrics["accuracy"], "macro_f1": metrics["macro_f1"], "category_accuracy": metrics["category_accuracy"], "physical_csv_rows": model_version["physical_csv_rows"], "valid_raw_examples": model_version["valid_raw_examples"], "generated_augmentation_examples": model_version["generated_augmentation_examples"], "effective_dataset_rows": model_version["effective_dataset_rows"], "train_rows": model_version["train_rows"], "validation_rows": model_version["validation_rows"], "test_rows": model_version["test_rows"], "group_count": model_version["group_count"], "skipped_rows": len(skipped_rows), "conflicts": len(report["conflicts"])}))


if __name__ == "__main__":
    main()
