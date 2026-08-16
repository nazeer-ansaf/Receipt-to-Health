<?php
declare(strict_types=1);

define('APP_NAME', 'Receipt-to-Health');
define('ROOT_DIR', dirname(__DIR__));
define('UPLOAD_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'uploads');
define('DATA_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'data');
define('RESULT_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'results');
define('OCR_DRAFT_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'ocr_drafts');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'receipt_to_health');
define('DB_USER', 'root');
define('DB_PASS', '');

function load_app_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\\\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

load_app_env(ROOT_DIR . DIRECTORY_SEPARATOR . '.env');
define('GOOGLE_CLIENT_ID', trim((string)(getenv('GOOGLE_CLIENT_ID') ?: '')));
define('APP_ENV', trim((string)(getenv('APP_ENV') ?: 'local')));
define('APP_URL', rtrim(trim((string)(getenv('APP_URL') ?: 'http://localhost/receipt-to-health')), '/'));
define('MAIL_FROM', trim((string)(getenv('MAIL_FROM') ?: 'no-reply@receipt-to-health.local')));

$configuredPython = trim((string)(getenv('RECEIPT_TO_HEALTH_PYTHON') ?: ''));
$localVenvPython = ROOT_DIR . DIRECTORY_SEPARATOR . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
define('PYTHON_COMMAND', $configuredPython !== '' ? $configuredPython : (is_file($localVenvPython) ? $localVenvPython : 'python'));
