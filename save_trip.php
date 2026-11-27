<?php
/**
 * Save a trip (.KMZ or images) into the signed-in user's folder.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/usage.php';

boot_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Method not allowed.', 405);
}

// A confirmed address is required, so the site cannot be used as free file
// storage by throwaway accounts.
$user = require_verified();
require_csrf();

$folderSlug = (string) $user['folder_slug'];
if (!preg_match('/^[a-z0-9_-]{1,64}$/', $folderSlug)) {
    json_fail('Invalid user directory identifier.', 400);
}

$tripSlug = preg_replace('/[^a-z0-9_-]/', '-', strtolower(trim((string) ($_POST['trip_slug'] ?? ''))));
$tripSlug = trim((string) $tripSlug, '-');
if ($tripSlug === '') {
    $tripSlug = 'trip-' . time();
}
$tripSlug = substr($tripSlug, 0, 60);

$saveMode = (string) ($_POST['save_mode'] ?? 'kmz_only');
if (!in_array($saveMode, ['kmz_only', 'images_only'], true)) {
    json_fail('Unknown save mode.', 400);
}

$maxKmzBytes   = 64 * 1024 * 1024; // 64 MB
$maxImageBytes = 16 * 1024 * 1024; // 16 MB per image

// Locked strictly to /trip/<folderSlug>/<tripSlug>
$tripDir = __DIR__ . '/trip/' . $folderSlug . '/' . $tripSlug;
if (!is_dir($tripDir)) {
    if (!@mkdir($tripDir, 0755, true) && !is_dir($tripDir)) {
        json_fail('Failed to create trip directory.', 500);
    }
    @file_put_contents($tripDir . '/index.html', '<!doctype html><title>Trip Assets</title>');
}

$relativeKmzPath = null;
$savedPhotos     = [];

if ($saveMode === 'kmz_only') {
    $file = $_FILES['kmz_file'] ?? null;

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_fail('Missing or invalid KMZ file.');
    }
    if ((int) $file['size'] > $maxKmzBytes) {
        json_fail('That KMZ file is too large (64 MB limit).');
    }
    if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'kmz') {
        json_fail('Only .kmz files are accepted.');
    }

    $kmzFilename = $tripSlug . '.kmz';
    if (!move_uploaded_file($file['tmp_name'], $tripDir . '/' . $kmzFilename)) {
        json_fail('Failed to write KMZ file.', 500);
    }

    $relativeKmzPath = "trip/$folderSlug/$tripSlug/$kmzFilename";
}

if ($saveMode === 'images_only') {
    $files = $_FILES['photos'] ?? null;

    if (!is_array($files) || !is_array($files['name'] ?? null)) {
        json_fail('No images uploaded.');
    }

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        if ((int) $files['size'][$i] > $maxImageBytes) {
            continue;
        }

        $tmp = (string) $files['tmp_name'][$i];
        if (!is_uploaded_file($tmp) || @getimagesize($tmp) === false) {
            continue; // not actually an image
        }

        $ext = strtolower(pathinfo((string) $files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            continue;
        }

        $safePhoto = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $files['name'][$i]));
        $safePhoto = substr((string) $safePhoto, 0, 120);
        if ($safePhoto === '' || $safePhoto === '.' || $safePhoto === '..') {
            continue;
        }

        if (move_uploaded_file($tmp, $tripDir . '/' . $safePhoto)) {
            $savedPhotos[] = "trip/$folderSlug/$tripSlug/$safePhoto";
        }
    }

    if (!$savedPhotos) {
        json_fail('No valid images were saved.');
    }
}

// Track the share link that was just created and how many bytes it uses.
$uploadedBytes = 0;
if ($relativeKmzPath !== null) {
    $absKmz = __DIR__ . '/' . $relativeKmzPath;
    if (is_file($absKmz)) {
        $uploadedBytes += (int) @filesize($absKmz);
    }
}
foreach ($savedPhotos as $savedPhoto) {
    $absPhoto = __DIR__ . '/' . $savedPhoto;
    if (is_file($absPhoto)) {
        $uploadedBytes += (int) @filesize($absPhoto);
    }
}

usage_log('link_created', $folderSlug, [
    'trip'   => $tripSlug,
    'mode'   => $saveMode,
    'path'   => $relativeKmzPath !== null ? $relativeKmzPath : "trip/$folderSlug/$tripSlug/",
    'bytes'  => $uploadedBytes,
    'photos' => count($savedPhotos),
]);

json_out([
    'success'      => true,
    'email'        => $user['email'],
    'folder_slug'  => $folderSlug,
    'save_mode'    => $saveMode,
    'kmz_path'     => $relativeKmzPath,
    'saved_photos' => $savedPhotos,
]);
