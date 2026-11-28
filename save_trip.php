<?php
/**
 * Save a trip (.KMZ or images) into the signed-in user's metered storage.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';

boot_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Method not allowed.', 405);
}

$user = require_verified();
require_csrf();

$folderSlug = (string) $user['folder_slug'];
if (!preg_match('/^[a-z0-9_-]{1,64}$/', $folderSlug)) {
    json_fail('Invalid user directory identifier.', 400);
}

$entitlement = billing_entitlement($user);
$tripSlug = preg_replace('/[^a-z0-9_-]/', '-', strtolower(trim((string) ($_POST['trip_slug'] ?? ''))));
$tripSlug = trim((string) $tripSlug, '-');
$tripSlug = substr($tripSlug !== '' ? $tripSlug : 'trip-' . time(), 0, 60);

$saveMode = (string) ($_POST['save_mode'] ?? 'kmz_only');
if (!in_array($saveMode, ['kmz_only', 'images_only'], true)) {
    json_fail('Unknown save mode.', 400);
}

$tripRoot = __DIR__ . '/trip/' . $folderSlug;
$tripDir = $tripRoot . '/' . $tripSlug;
$uploads = [];
$relativeKmzPath = null;
$savedPhotos = [];

if ($saveMode === 'kmz_only') {
    $file = $_FILES['kmz_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_fail('Missing or invalid KMZ file. If it is too large, check the PHP upload limit.');
    }
    if ((int) $file['size'] > $entitlement['kmz_max_bytes']) {
        json_fail(
            'KMZ files are limited to ' . human_bytes($entitlement['kmz_max_bytes']) . '.',
            413,
            ['error_code' => 'FILE_TOO_LARGE']
        );
    }
    if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'kmz'
        || !is_uploaded_file((string) $file['tmp_name'])) {
        json_fail('Only valid uploaded .kmz files are accepted.');
    }

    $filename = $tripSlug . '.kmz';
    $destination = $tripDir . '/' . $filename;
    $uploads[] = [
        'tmp' => (string) $file['tmp_name'],
        'destination' => $destination,
        'size' => (int) $file['size'],
        'relative' => 'trip/' . $folderSlug . '/' . $tripSlug . '/' . $filename,
        'kind' => 'kmz',
    ];
} else {
    $files = $_FILES['photos'] ?? null;
    if (!is_array($files) || !is_array($files['name'] ?? null)) {
        json_fail('No images uploaded.');
    }

    $plannedNames = [];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            continue;
        }

        $size = (int) ($files['size'][$i] ?? 0);
        if ($size > $entitlement['image_max_bytes']) {
            json_fail(
                'Each image is limited to ' . human_bytes($entitlement['image_max_bytes'])
                . ' on your current plan.',
                413,
                ['error_code' => 'FILE_TOO_LARGE']
            );
        }

        $tmp = (string) ($files['tmp_name'][$i] ?? '');
        if (!is_uploaded_file($tmp) || @getimagesize($tmp) === false) {
            continue;
        }

        $ext = strtolower(pathinfo((string) ($files['name'][$i] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            continue;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $files['name'][$i]));
        $safeName = substr((string) $safeName, 0, 120);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            continue;
        }

        $candidate = $safeName;
        $suffix = 1;
        $stem = pathinfo($safeName, PATHINFO_FILENAME);
        $extension = pathinfo($safeName, PATHINFO_EXTENSION);
        while (isset($plannedNames[$candidate]) || file_exists($tripDir . '/' . $candidate)) {
            $suffix++;
            $candidate = $stem . '-' . $suffix . ($extension !== '' ? '.' . $extension : '');
            $candidate = substr($candidate, 0, 120);
        }
        $plannedNames[$candidate] = true;

        $uploads[] = [
            'tmp' => $tmp,
            'destination' => $tripDir . '/' . $candidate,
            'size' => $size,
            'relative' => 'trip/' . $folderSlug . '/' . $tripSlug . '/' . $candidate,
            'kind' => 'image',
        ];
    }

    if (!$uploads) {
        json_fail('No valid JPEG, PNG or WebP images were uploaded.');
    }
}

try {
    $lock = lock_user_storage($folderSlug);
} catch (Throwable $e) {
    audit('Could not lock storage for user ' . (int) $user['id'] . ': ' . $e->getMessage());
    json_fail('Could not prepare your storage. Please try again.', 500);
}

if (!is_dir($tripDir) && !@mkdir($tripDir, 0755, true) && !is_dir($tripDir)) {
    unlock_user_storage($lock);
    json_fail('Failed to create trip directory.', 500);
}

$usedBytes = user_storage_bytes($folderSlug);
$projectedBytes = $usedBytes;
foreach ($uploads as $upload) {
    if (is_file($upload['destination'])) {
        $projectedBytes -= (int) filesize($upload['destination']);
    }
    $projectedBytes += $upload['size'];
}

if ($projectedBytes > $entitlement['storage_quota_bytes']) {
    unlock_user_storage($lock);
    json_fail(
        'This upload would exceed your ' . human_bytes($entitlement['storage_quota_bytes'])
        . ' storage limit. Upgrade your plan or delete saved trips to make room.',
        409,
        [
            'error_code' => 'QUOTA_EXCEEDED',
            'storage_used_bytes' => $usedBytes,
            'storage_quota_bytes' => $entitlement['storage_quota_bytes'],
        ]
    );
}

foreach ($uploads as $upload) {
    if (!move_uploaded_file($upload['tmp'], $upload['destination'])) {
        unlock_user_storage($lock);
        json_fail('Failed to save an uploaded file. Please try again.', 500);
    }

    if ($upload['kind'] === 'kmz') {
        $relativeKmzPath = $upload['relative'];
    } else {
        $savedPhotos[] = $upload['relative'];
    }
}

if (!is_file($tripDir . '/index.html')) {
    @file_put_contents($tripDir . '/index.html', '<!doctype html><title>Trip Assets</title>');
}

$usedBytes = user_storage_bytes($folderSlug);
unlock_user_storage($lock);

json_out([
    'success' => true,
    'email' => $user['email'],
    'folder_slug' => $folderSlug,
    'save_mode' => $saveMode,
    'kmz_path' => $relativeKmzPath,
    'saved_photos' => $savedPhotos,
    'storage_used_bytes' => $usedBytes,
    'storage_quota_bytes' => $entitlement['storage_quota_bytes'],
]);
