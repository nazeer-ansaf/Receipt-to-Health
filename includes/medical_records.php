<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/db.php';

function medical_records_dir(): string
{
    return DATA_DIR . DIRECTORY_SEPARATOR . 'medical_records';
}

function medical_record_files_dir(?array $user = null): string
{
    return medical_records_dir() . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . profile_storage_key($user);
}

function medical_record_index_path(?array $user = null): string
{
    return medical_records_dir() . DIRECTORY_SEPARATOR . profile_storage_key($user) . '.json';
}

function medical_record_allowed_extensions(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt'];
}

function medical_record_max_bytes(): int
{
    return 10 * 1024 * 1024;
}

function load_medical_records(?array $user = null): array
{
    $path = medical_record_index_path($user);

    if (!is_file($path)) {
        return [];
    }

    $records = json_decode((string)file_get_contents($path), true);

    if (!is_array($records)) {
        return [];
    }

    $records = array_values(array_filter($records, static fn($record) => is_array($record)));
    usort($records, static fn($a, $b) => strcmp((string)($b['uploaded_at'] ?? ''), (string)($a['uploaded_at'] ?? '')));

    return $records;
}

function save_medical_records(array $records, ?array $user = null): void
{
    ensure_directory(medical_records_dir());
    file_put_contents(medical_record_index_path($user), json_encode(array_values($records), JSON_PRETTY_PRINT));
}

function find_medical_record(string $recordId, ?array $user = null): ?array
{
    $recordId = preg_replace('/[^a-zA-Z0-9_-]/', '', $recordId);

    foreach (load_medical_records($user) as $record) {
        if (($record['id'] ?? '') === $recordId) {
            return $record;
        }
    }

    return null;
}

function store_uploaded_medical_record(array $file, array $payload = [], ?array $user = null): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(medical_record_upload_error_message($error));
    }

    $originalName = basename((string)($file['name'] ?? 'medical-record'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, medical_record_allowed_extensions(), true)) {
        throw new InvalidArgumentException('Only PDF, JPG, PNG, WEBP, and TXT medical records are allowed.');
    }

    $sizeBytes = (int)($file['size'] ?? 0);

    if ($sizeBytes <= 0) {
        throw new InvalidArgumentException('The selected medical record is empty.');
    }

    if ($sizeBytes > medical_record_max_bytes()) {
        throw new InvalidArgumentException('Medical records must be 10 MB or smaller.');
    }

    $temporaryPath = (string)($file['tmp_name'] ?? '');
    $mimeType = detect_medical_record_mime_type($temporaryPath);

    if (!medical_record_mime_matches_extension($mimeType, $extension)) {
        throw new InvalidArgumentException('The selected file type does not match the allowed medical record formats.');
    }

    ensure_directory(medical_records_dir());
    ensure_directory(medical_record_files_dir($user));

    $recordId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $storedName = $recordId . '.' . $extension;
    $storedPath = medical_record_files_dir($user) . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($temporaryPath, $storedPath)) {
        throw new RuntimeException('Could not save uploaded medical record.');
    }

    $title = trim((string)($payload['title'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));

    $record = [
        'id' => $recordId,
        'user_id' => current_user_id(),
        'storage_key' => profile_storage_key($user),
        'title' => substr($title, 0, 160),
        'notes' => substr($notes, 0, 1000),
        'original_name' => substr($originalName, 0, 190),
        'stored_name' => $storedName,
        'path' => $storedPath,
        'extension' => $extension,
        'mime_type' => $mimeType,
        'size_bytes' => $sizeBytes,
        'uploaded_at' => date('c'),
    ];

    $records = load_medical_records($user);
    array_unshift($records, $record);
    save_medical_records($records, $user);
    persist_medical_record_metadata($record);

    return $record;
}

function medical_record_upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected medical record is too large.',
        UPLOAD_ERR_PARTIAL => 'The medical record upload was incomplete.',
        UPLOAD_ERR_NO_FILE => 'Please choose a medical record to upload.',
        default => 'Medical record upload failed.',
    };
}

function detect_medical_record_mime_type(string $path): string
{
    if ($path !== '' && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $mimeType = finfo_file($finfo, $path);
            finfo_close($finfo);

            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }
    }

    return 'application/octet-stream';
}

function medical_record_mime_matches_extension(string $mimeType, string $extension): bool
{
    if ($mimeType === 'application/octet-stream') {
        return true;
    }

    if ($extension === 'pdf') {
        return in_array($mimeType, ['application/pdf', 'application/x-pdf'], true);
    }

    if ($extension === 'txt') {
        return str_starts_with($mimeType, 'text/');
    }

    return str_starts_with($mimeType, 'image/');
}

function format_medical_record_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
}

function ensure_medical_records_table(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS medical_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            storage_key VARCHAR(160) NOT NULL,
            record_uid VARCHAR(80) NOT NULL,
            title VARCHAR(190) NULL,
            notes TEXT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(20) NOT NULL,
            mime_type VARCHAR(120) NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX medical_records_user_id_index (user_id),
            INDEX medical_records_storage_key_index (storage_key),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )"
    );
}

function persist_medical_record_metadata(array $record): void
{
    try {
        ensure_medical_records_table();

        $statement = db()->prepare(
            'INSERT INTO medical_records
                (user_id, storage_key, record_uid, title, notes, original_name, stored_path, file_type, mime_type, file_size)
             VALUES
                (:user_id, :storage_key, :record_uid, :title, :notes, :original_name, :stored_path, :file_type, :mime_type, :file_size)'
        );
        $statement->execute([
            ':user_id' => $record['user_id'] ?? null,
            ':storage_key' => $record['storage_key'] ?? '',
            ':record_uid' => $record['id'] ?? '',
            ':title' => $record['title'] ?? '',
            ':notes' => $record['notes'] ?? '',
            ':original_name' => $record['original_name'] ?? '',
            ':stored_path' => $record['path'] ?? '',
            ':file_type' => $record['extension'] ?? '',
            ':mime_type' => $record['mime_type'] ?? '',
            ':file_size' => (int)($record['size_bytes'] ?? 0),
        ]);
    } catch (Throwable $exception) {
        // JSON metadata remains the source of truth when MySQL is unavailable.
    }
}
