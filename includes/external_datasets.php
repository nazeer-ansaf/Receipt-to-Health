<?php
declare(strict_types=1);

/**
 * Small, filesystem-backed helpers used by the Admin Console.
 *
 * The external dataset worker is optional. These helpers keep the admin page
 * safe when no external jobs have been staged, and validate all paths before
 * touching the configured data directories.
 */

function external_bootstrap(): void
{
    ensure_directory(EXTERNAL_IMPORT_DIR);
    ensure_directory(EXTERNAL_DATASET_DIR);
    ensure_directory(EXTERNAL_JOB_DIR);
}

function external_safe_id(string $value): string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $value)) {
        throw new InvalidArgumentException('Invalid external dataset job ID.');
    }

    return $value;
}

function external_job_path(string $jobId): string
{
    return EXTERNAL_JOB_DIR . DIRECTORY_SEPARATOR . external_safe_id($jobId) . '.json';
}

function external_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function external_write_json(string $path, array $payload): void
{
    external_bootstrap();
    $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($temporaryPath, $encoded . PHP_EOL, LOCK_EX) === false) {
        @unlink($temporaryPath);
        throw new RuntimeException('Could not save external dataset job metadata.');
    }

    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Could not finalize external dataset job metadata.');
    }
}

function external_list_jobs(): array
{
    external_bootstrap();
    $jobs = [];
    $paths = glob(EXTERNAL_JOB_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($paths as $path) {
        $job = external_read_json($path);
        if (isset($job['id']) && is_string($job['id'])) {
            $jobs[] = $job;
        }
    }

    usort($jobs, static function (array $left, array $right): int {
        return strcmp((string)($right['updated_at'] ?? $right['created_at'] ?? ''), (string)($left['updated_at'] ?? $left['created_at'] ?? ''));
    });

    return $jobs;
}

function external_list_inbox_files(): array
{
    external_bootstrap();
    $files = [];
    foreach (scandir(EXTERNAL_IMPORT_DIR) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = EXTERNAL_IMPORT_DIR . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            $files[] = [
                'name' => $name,
                'size' => (int)(filesize($path) ?: 0),
            ];
        }
    }

    usort($files, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));
    return $files;
}

function external_inbox_path(string $name): string
{
    $name = trim($name);
    if ($name === '' || basename($name) !== $name || str_contains($name, '\0')) {
        throw new InvalidArgumentException('Invalid staged external dataset path.');
    }

    $path = EXTERNAL_IMPORT_DIR . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        throw new RuntimeException('The staged external dataset could not be found.');
    }

    return $path;
}

function external_save_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('External dataset upload failed.');
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['csv', 'tsv', 'zip', 'json', 'jsonl', 'txt'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('External dataset must be CSV, TSV, ZIP, JSON, JSONL, or TXT.');
    }

    $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    external_bootstrap();
    $destination = EXTERNAL_IMPORT_DIR . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string)($file['tmp_name'] ?? ''), $destination)) {
        throw new RuntimeException('Could not save the external dataset upload.');
    }

    return $name;
}

function external_new_job_id(): string
{
    return 'external_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
}

function external_store_job(array $job): array
{
    $job['updated_at'] = date(DATE_ATOM);
    external_write_json(external_job_path((string)$job['id']), $job);
    return $job;
}

function external_create_local_job(string $relativePath): array
{
    $sourcePath = external_inbox_path($relativePath);
    $job = [
        'id' => external_new_job_id(),
        'title' => basename($sourcePath),
        'provider' => 'local',
        'source_name' => basename($sourcePath),
        'source_path' => basename($sourcePath),
        'source_url' => '',
        'dataset_type' => 'pending inspection',
        'license' => 'Unclear / not supplied',
        'status' => 'Queued',
        'step' => 'Staged for external dataset inspection',
        'error' => '',
        'candidate_count' => 0,
        'candidates' => [],
        'progress' => [
            'files_total' => 1,
            'files_processed' => 0,
            'rows_scanned' => 0,
            'accepted_rows' => 0,
        ],
        'created_at' => date(DATE_ATOM),
    ];

    return external_store_job($job);
}

function external_change_job_status(string $jobId, string $status): array
{
    $job = external_read_json(external_job_path($jobId));
    if (!$job) {
        throw new RuntimeException('External dataset job not found.');
    }

    $job['status'] = $status;
    $job['step'] = $status === 'Cancelled' ? 'Cancelled by administrator' : (string)($job['step'] ?? '');
    return external_store_job($job);
}

function external_retry_job(string $jobId): array
{
    $job = external_read_json(external_job_path($jobId));
    if (!$job) {
        throw new RuntimeException('External dataset job not found.');
    }

    $job['status'] = 'Queued';
    $job['step'] = 'Queued for external dataset inspection';
    $job['error'] = '';
    return external_store_job($job);
}

function external_parse_kaggle_slug(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $value)) {
        throw new InvalidArgumentException('Use a Kaggle dataset in owner/dataset-name format.');
    }

    return $value;
}

function external_start_job(array $payload): array
{
    $job = [
        'id' => external_new_job_id(),
        'title' => (string)($payload['title'] ?? 'External dataset'),
        'provider' => (string)($payload['provider'] ?? 'external'),
        'slug' => (string)($payload['slug'] ?? ''),
        'source_name' => (string)($payload['title'] ?? 'External dataset'),
        'source_path' => (string)($payload['source_path'] ?? ''),
        'source_url' => (string)($payload['source_url'] ?? ''),
        'dataset_type' => 'pending inspection',
        'license' => 'Unclear / not supplied',
        'status' => 'Queued',
        'step' => 'Queued for external dataset inspection',
        'error' => '',
        'candidate_count' => 0,
        'candidates' => [],
        'progress' => [
            'files_total' => 0,
            'files_processed' => 0,
            'rows_scanned' => 0,
            'accepted_rows' => 0,
        ],
        'created_at' => date(DATE_ATOM),
    ];

    return external_store_job($job);
}

function external_candidate_records(array $job, int $limit = 50): array
{
    $candidates = is_array($job['candidates'] ?? null) ? $job['candidates'] : [];
    $result = [];
    foreach (array_slice($candidates, 0, max(0, $limit)) as $index => $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $candidate['candidate_index'] = (int)($candidate['candidate_index'] ?? $index);
        $result[] = $candidate;
    }

    return $result;
}

function external_review_candidate(array $job, int $index, string $decision, string $label = ''): array
{
    if (!isset($job['candidates'][$index]) || !is_array($job['candidates'][$index])) {
        throw new RuntimeException('External dataset candidate not found.');
    }

    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approve', 'reject', 'unknown'], true)) {
        throw new InvalidArgumentException('Invalid external candidate decision.');
    }

    $job['candidates'][$index]['verification_status'] = $decision;
    if ($label !== '') {
        $job['candidates'][$index]['proposed_label'] = trim($label);
    }
    $job['candidate_count'] = count($job['candidates']);
    return external_store_job($job);
}
