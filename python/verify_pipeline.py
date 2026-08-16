import json
import os
import subprocess
import sys
import tempfile


ROOT_DIR = os.path.dirname(os.path.dirname(__file__))
PROCESS_SCRIPT = os.path.join(ROOT_DIR, "python", "process_receipt.py")
SAMPLE_RECEIPT = os.path.join(ROOT_DIR, "samples", "demo_receipt.txt")


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


def main():
    if not os.path.isfile(SAMPLE_RECEIPT):
        raise AssertionError(f"Missing sample receipt: {SAMPLE_RECEIPT}")

    result = run_pipeline(SAMPLE_RECEIPT)
    assert_sample_receipt_contract(result)
    assert_unmatched_lines_contract()

    print(json.dumps({
        "status": "ok",
        "sample_receipt": os.path.relpath(SAMPLE_RECEIPT, ROOT_DIR),
        "ml_status": result["ml_classification"]["status"],
        "score": result["health_score"]["score"],
        "recommendation_count": len(result["recommendations"]),
    }))


if __name__ == "__main__":
    main()
