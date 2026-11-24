<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$userDir = preg_replace('/[^a-z0-9_-]/', '', strtolower($_POST['user_dir'] ?? ''));
$passcode = trim($_POST['passcode'] ?? '');
$tripSlug = preg_replace('/[^a-z0-9_-]/', '-', strtolower($_POST['trip_slug'] ?? 'trip-' . time()));

if (strlen($userDir) < 2 || strlen($passcode) < 4) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Directory name (min 2 chars) and passcode (min 4 chars) are required.']);
    exit;
}

$baseDir = __DIR__ . '/trips/' . $userDir;
$passHashFile = $baseDir . '/.passcode_hash';

// Create user directory on first use, or verify passcode if directory already exists
if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not create user directory on server.']);
        exit;
    }
    file_put_contents($passHashFile, password_hash($passcode, PASSWORD_DEFAULT));
    // Prevent directory listing in browser
    file_put_contents($baseDir . '/index.html', '<!doctype html><title>Private Directory</title>');
} else {
    if (!file_exists($passHashFile) || !password_verify($passcode, trim(file_get_contents($passHashFile)))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Incorrect passcode for this existing directory.']);
        exit;
    }
}

$tripDir = $baseDir . '/' . $tripSlug;
if (!is_dir($tripDir)) {
    mkdir($tripDir, 0755, true);
}

// 1. Save the .KMZ file
if (!isset($_FILES['kmz_file']) || $_FILES['kmz_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid KMZ upload.']);
    exit;
}

$kmzFilename = $tripSlug . '.kmz';
$kmzDest = $tripDir . '/' . $kmzFilename;
if (!move_uploaded_file($_FILES['kmz_file']['tmp_name'], $kmzDest)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save KMZ file.']);
    exit;
}

// 2. Save individual JPEG photos into the user's trip directory
$savedPhotos = [];
if (isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
    $count = count($_FILES['photos']['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
            $origName = basename($_FILES['photos']['name'][$i]);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'webp', 'png'])) {
                $safePhoto = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
                $photoDest = $tripDir . '/' . $safePhoto;
                if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $photoDest)) {
                    $savedPhotos[] = "trips/$userDir/$tripSlug/$safePhoto";
                }
            }
        }
    }
}

$relativeKmzPath = "trips/$userDir/$tripSlug/$kmzFilename";

echo json_encode([
    'success' => true,
    'kmz_path' => $relativeKmzPath,
    'saved_photos' => $savedPhotos
]);
