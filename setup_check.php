<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

function status_badge(bool $ok): string
{
    return $ok ? '<span class="ok">OK</span>' : '<span class="bad">Needs fix</span>';
}

function command_output(string $command): string
{
    $output = shell_exec($command . ' 2>&1');
    return trim((string)$output);
}

$checks = [];

$checks[] = [
    'label' => 'PHP version',
    'ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'detail' => PHP_VERSION,
];

$checks[] = [
    'label' => 'Upload directory',
    'ok' => is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR),
    'detail' => UPLOAD_DIR,
];

$checks[] = [
    'label' => 'Result directory',
    'ok' => is_dir(RESULT_DIR) && is_writable(RESULT_DIR),
    'detail' => RESULT_DIR,
];

$pythonVersion = command_output(escapeshellcmd(PYTHON_COMMAND) . ' --version');
$checks[] = [
    'label' => 'Python command',
    'ok' => stripos($pythonVersion, 'Python') !== false,
    'detail' => PYTHON_COMMAND . ' -> ' . ($pythonVersion ?: 'No output'),
];

$pipelineOutput = command_output(
    escapeshellcmd(PYTHON_COMMAND)
    . ' '
    . escapeshellarg(ROOT_DIR . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'process_receipt.py')
    . ' --input '
    . escapeshellarg(ROOT_DIR . DIRECTORY_SEPARATOR . 'samples' . DIRECTORY_SEPARATOR . 'demo_receipt.txt')
    . ' --family-size 4 --age-group mixed --conditions diabetes'
);

$decodedPipeline = json_decode($pipelineOutput, true);
$checks[] = [
    'label' => 'Python AI pipeline',
    'ok' => is_array($decodedPipeline) && isset($decodedPipeline['health_score']),
    'detail' => is_array($decodedPipeline)
        ? 'Score: ' . $decodedPipeline['health_score']['score']
        : ($pipelineOutput ?: 'No output'),
];

$dbDetail = '';
$dbOk = false;
try {
    require_once __DIR__ . '/includes/db.php';
    $dbOk = (bool) db()->query('SELECT 1')->fetchColumn();
    $dbDetail = DB_NAME . ' at ' . DB_HOST;
} catch (Throwable $exception) {
    $dbDetail = $exception->getMessage();
}

$checks[] = [
    'label' => 'Database connection',
    'ok' => $dbOk,
    'detail' => $dbDetail,
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup Check - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .check-list {
            display: grid;
            gap: 12px;
        }

        .check-row {
            display: grid;
            grid-template-columns: 180px 110px 1fr;
            gap: 12px;
            align-items: start;
            padding: 12px 0;
            border-bottom: 1px solid var(--line);
        }

        .ok,
        .bad {
            display: inline-flex;
            width: max-content;
            padding: 4px 8px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.86rem;
        }

        .ok {
            color: #155c42;
            background: #dff3e7;
        }

        .bad {
            color: #8a2f2f;
            background: #f8dedc;
        }

        code {
            overflow-wrap: anywhere;
        }

        @media (max-width: 760px) {
            .check-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="panel intro">
            <div>
                <p class="eyebrow">Local setup</p>
                <h1>Setup Check</h1>
                <p class="lede">Use this page while developing, then remove it before final deployment.</p>
            </div>
            <a class="button ghost" href="index.php">Back to upload</a>
        </section>

        <section class="panel">
            <div class="check-list">
                <?php foreach ($checks as $check): ?>
                    <div class="check-row">
                        <strong><?= e($check['label']) ?></strong>
                        <?= status_badge($check['ok']) ?>
                        <code><?= e($check['detail']) ?></code>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>

