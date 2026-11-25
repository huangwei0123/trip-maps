<?php
session_start();
header('Content-Type: application/json');

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

$username = preg_replace('/[^a-z0-9_-]/', '', strtolower($_SESSION['username']));
$tripSlug = preg_replace('/[^a-z0-9_-]/', '-', strtolower(trim($_POST['trip_slug'] ?? '')));
$tripSlug = trim($tripSlug, '-') ?: 'trip-' . time();
$saveMode = $_POST['save_mode'] ?? 'kmz_only'; // 'kmz_only' or 'images_only'

// Locked strictly to /trip/<username>/<tripSlug>
$tripDir = __DIR__ . '/trip/' . $username . '/' . $tripSlug;
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
    $relativeKmzPath = "trip/$username/$tripSlug/$kmzFilename";
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
                    $savedPhotos[] = "trip/$username/$tripSlug/$safePhoto";
                }
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'username' => $username,
    'save_mode' => $saveMode,
    'kmz_path' => $relativeKmzPath,
    'saved_photos' => $savedPhotos
]);
