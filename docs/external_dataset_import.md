# External Dataset Import

The Admin console's **External Dataset Import** section uses a local, file-backed workflow:

`Kaggle / controlled local file → staged workspace → safe extraction → streaming inspection → normalized JSONL candidates → Admin review → approved training evidence`

External files live under `data/external_imports/` (staged inputs), `data/external_datasets/<job-id>/` (source, extracted data, schema, and candidates), and `data/external_jobs/` (small job metadata). These directories are ignored by Git.

## Safety and behavior

- Kaggle uses the official Python Kaggle API and standard credentials. No credentials are stored in job metadata. The current `venv` does not contain the `kaggle` package, so Kaggle jobs report the missing dependency/configuration instead of scraping or silently installing it.
- CSV and JSONL are processed incrementally. A massive JSON array is reported as unsupported until converted to JSONL.
- ZIP extraction rejects traversal/absolute paths and enforces archive size, extracted bytes, file count, and configured batch limits.
- Dataset files are never executed. Source SHA-256, provider, dataset ID, source file, row ID, and license fields are retained in candidates.
- Product text, category/label, brand, quantity, and common nutrition columns are detected heuristically. Missing optional values remain empty.
- Image-only imports are classified separately and use installed Tesseract when available; OCR failures remain failures and do not create demo text.
- Every candidate starts `unverified`. Approval performs duplicate/conflict checks before appending `source=external_<provider>` evidence to the existing approved training CSV. Importing or inspecting alone never retrains, changes model hashes, or enters the real holdout.

## Limits

Defaults are configurable through environment variables: `EXTERNAL_MAX_ARCHIVE_BYTES`, `EXTERNAL_MAX_EXTRACTED_BYTES`, `EXTERNAL_MAX_EXTRACTED_FILES`, and `EXTERNAL_DATASET_BATCH_SIZE`. The implementation is bounded by available disk, OS/PHP limits, upstream Kaggle behavior, and formats supported by the worker; it does not promise unlimited size or true Kaggle resume support.
