<?php
declare(strict_types=1);

define('APP_NAME', 'Receipt-to-Health');
define('ROOT_DIR', dirname(__DIR__));
define('UPLOAD_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'uploads');
define('DATA_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'data');
define('RESULT_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'results');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'receipt_to_health');
define('DB_USER', 'root');
define('DB_PASS', '');

define('PYTHON_COMMAND', getenv('RECEIPT_TO_HEALTH_PYTHON') ?: 'python');
