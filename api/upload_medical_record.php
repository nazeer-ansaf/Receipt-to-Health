<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/medical_records.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Invalid request method.'], 405);
}

if (!has_app_access()) {
    json_response(['error' => 'Login or guest mode is required before uploading medical records.'], 403);
}

try {
    if (!isset($_FILES['medical_record']) || !is_array($_FILES['medical_record'])) {
        throw new InvalidArgumentException('Please choose a medical record to upload.');
    }

    store_uploaded_medical_record($_FILES['medical_record'], $_POST, current_user());
    header('Location: ../profile_setup.php?record_status=uploaded#medical-records');
    exit;
} catch (Throwable $exception) {
    header('Location: ../profile_setup.php?record_error=' . urlencode($exception->getMessage()) . '#medical-records');
    exit;
}
