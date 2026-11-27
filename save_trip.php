<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/lib/usage.php';

function emailToFolderSlug(string $email): string {
    $slug = str_replace('@', '_at_', strtolower(trim($email)));
    $slug = preg_replace('/[^a-z0-9_-]/', '_', $slug);
    $slug = preg_replace('/_+/', '_', $slug);
    return trim($slug, '_');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Enforce logged-in session
if (empty($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in or register before saving to the site.']);
    exit;
}

$rawFolder = $_SESSION['folder_slug'] ?? emailToFolderSlug($_SESSION['username']);
$folderSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($rawFolder));
if ($folderSlug === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user directory identifier.']);
    exit;
}

$tripSlug = preg_replace('/[^a-z0-9_-]/', '-', strtolower(trim($_POST['trip_slug'] ?? '')));
$tripSlug = trim($tripSlug, '-') ?: 'trip-' . time();
$saveMode = $_POST['save_mode'] ?? 'kmz_only'; // 'kmz_only' or 'images_only'

// Locked strictly to /trip/<folderSlug>/<tripSlug>
$tripDir = __DIR__ . '/trip/' . $folderSlug . '/' . $tripSlug;
if (!is_dir($tripDir)) {
    if (!mkdir($tripDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create trip directory.']);
        exit;
    }
    file_put_contents($tripDir . '/index.html', '<!doctype html><title>Trip Assets</title>');
}

$relativeKmzPath = null;
$savedPhotos = [];

if ($saveMode === 'kmz_only') {
    if (!isset($_FILES['kmz_file']) || $_FILES['kmz_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing or invalid KMZ file.']);
        exit;
    }

    $kmzFilename = $tripSlug . '.kmz';
    $kmzDest = $tripDir . '/' . $kmzFilename;
    if (!move_uploaded_file($_FILES['kmz_file']['tmp_name'], $kmzDest)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to write KMZ file.']);
        exit;
    }
    $relativeKmzPath = "trip/$folderSlug/$tripSlug/$kmzFilename";
}

if ($saveMode === 'images_only') {
    if (!isset($_FILES['photos']) || !is_array($_FILES['photos']['name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No images uploaded.']);
        exit;
    }

    $count = count($_FILES['photos']['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
            $origName = basename($_FILES['photos']['name'][$i]);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'webp', 'png'])) {
                $safePhoto = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
                $photoDest = $tripDir . '/' . $safePhoto;
                if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $photoDest)) {
                    $savedPhotos[] = "trip/$folderSlug/$tripSlug/$safePhoto";
                }
            }
        }
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

usage_log('link_created', $_SESSION['username'], [
    'trip' => $tripSlug,
    'mode' => $saveMode,
    'path' => $relativeKmzPath !== null ? $relativeKmzPath : "trip/$folderSlug/$tripSlug/",
    'bytes' => $uploadedBytes,
    'photos' => count($savedPhotos),
]);

echo json_encode([
    'success' => true,
    'username' => $_SESSION['username'],
    'folder_slug' => $folderSlug,
    'save_mode' => $saveMode,
    'kmz_path' => $relativeKmzPath,
    'saved_photos' => $savedPhotos
]);
