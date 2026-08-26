import json
import os
import subprocess
import sys
import tempfile
import sys


ROOT_DIR = os.path.dirname(os.path.dirname(__file__))
PROCESS_SCRIPT = os.path.join(ROOT_DIR, "python", "process_receipt.py")
SAMPLE_RECEIPT = os.path.join(ROOT_DIR, "samples", "demo_receipt.txt")
sys.path.insert(0, os.path.dirname(__file__))


def run_pipeline(input_path):
    completed = subprocess.run(
        [
            sys.executable,
            PROCESS_SCRIPT,
            "--input",
            input_path,
            "--family-size",
            "2",
            "--age-group",
            "adult",
            "--conditions",
            "diabetes,hypertension",
            "--health-notes",
            "low salt diabetic household",
        ],
        check=False,
        capture_output=True,
        text=True,
    )

    if completed.returncode != 0:
        raise AssertionError(f"Pipeline exited with {completed.returncode}: {completed.stderr.strip()}")

    try:
        return json.loads(completed.stdout)
    except json.JSONDecodeError as exception:
        raise AssertionError(f"Pipeline did not return valid JSON: {exception}") from exception


def assert_sample_receipt_contract(result):
    if not isinstance(result, dict):
        raise AssertionError("Sample receipt output is not a JSON object.")

    ml_status = result.get("ml_classification", {})
    if not ml_status.get("enabled") or ml_status.get("status") != "loaded":
        raise AssertionError(f"ML model is not loaded: {ml_status}")

    score = result.get("health_score", {}).get("score")
    if not isinstance(score, (int, float)):
        raise AssertionError("Health score is missing or not numeric.")

    recommendations = result.get("recommendations")
    if not isinstance(recommendations, list) or not recommendations:
        raise AssertionError("Recommendations are missing.")

    items = result.get("items")
    if not isinstance(items, list) or not items:
        raise AssertionError("Sample receipt did not produce detected items.")


def assert_unmatched_lines_contract():
    unmatched_line = "qxzv-not-a-food-98765 1"
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".txt", delete=False)

    try:
        with handle:
            handle.write("milk 1\n")
            handle.write(unmatched_line + "\n")

        result = run_pipeline(handle.name)
        unmatched_lines = result.get("unmatched_lines", [])

        if unmatched_line.lower() not in unmatched_lines:
            raise AssertionError(f"Expected unmatched line was not reported: {unmatched_lines}")

        detected_names = {str(item.get("name", "")).lower() for item in result.get("items", [])}
        if "qxzv-not-a-food-98765" in detected_names:
            raise AssertionError("Unmatched gibberish was accepted as a scored food item.")

        for prediction in result.get("unmatched_line_predictions", []):
            if prediction.get("line") == unmatched_line.lower() and prediction.get("suggestion_type") != "suggested_category_only":
                raise AssertionError(f"Unmatched fallback is not marked as category-only: {prediction}")
    finally:
        try:
            os.unlink(handle.name)
        except OSError:
            pass


def assert_group_split_contract():
    import train_food_model

    rows, _, _, _, _ = train_food_model.load_dataset(os.path.join(ROOT_DIR, "data", "training_food_items.csv"))
    splits, _ = train_food_model.split_groups(rows)
    groups = {
        name: {rows[index]["variant_group"] for index in indices}
        for name, indices in splits.items()
    }
    if groups["train"] & groups["validation"] or groups["train"] & groups["test"] or groups["validation"] & groups["test"]:
        raise AssertionError(f"Variant groups cross evaluation splits: {groups}")


def assert_ocr_failure_contract():
    import process_receipt

    text = process_receipt.extract_text(os.path.join(ROOT_DIR, "missing-receipt-for-verification.jpg"))
    if text.strip() or process_receipt.OCR_STATUS.get("status") != "failure":
        raise AssertionError("Normal OCR failure produced fallback/demo text.")

    demo_text = process_receipt.extract_text(os.path.join(ROOT_DIR, "missing-receipt-for-demo.jpg"), demo_mode=True)
    if "milk 2" not in demo_text or process_receipt.OCR_STATUS.get("status") != "demo_text":
        raise AssertionError("Explicit demo mode fallback is not working.")


def main():
    if not os.path.isfile(SAMPLE_RECEIPT):
        raise AssertionError(f"Missing sample receipt: {SAMPLE_RECEIPT}")

    result = run_pipeline(SAMPLE_RECEIPT)
    assert_sample_receipt_contract(result)
    assert_unmatched_lines_contract()
    assert_group_split_contract()
    assert_ocr_failure_contract()

    unknown_evaluation = {}
    metrics_path = os.path.join(ROOT_DIR, "python", "models", "food_classifier_metrics.json")
    if os.path.isfile(metrics_path):
        with open(metrics_path, "r", encoding="utf-8") as metrics_file:
            unknown_evaluation = json.load(metrics_file).get("unknown_evaluation", {})

    print(json.dumps({
        "status": "ok",
        "sample_receipt": os.path.relpath(SAMPLE_RECEIPT, ROOT_DIR),
        "ml_status": result["ml_classification"]["status"],
        "score": result["health_score"]["score"],
        "recommendation_count": len(result["recommendations"]),
        "group_split_status": "ok",
        "ocr_failure_status": "ok",
        "unknown_rejection_rate": unknown_evaluation.get("rejection_rate"),
    }))


if __name__ == "__main__":
    main()
