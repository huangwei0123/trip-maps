<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$username = preg_replace('/[^a-z0-9_-]/', '', strtolower($_SESSION['username']));
$userRoot = __DIR__ . '/trip/' . $username;
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'list') {
    $trips = [];
    if (is_dir($userRoot)) {
        $subDirs = scandir($userRoot);
        foreach ($subDirs as $slug) {
            if ($slug === '.' || $slug === '..') continue;
            $tripPath = $userRoot . '/' . $slug;
            if (!is_dir($tripPath)) continue;

            $kmzFiles = glob($tripPath . '/*.kmz');
            $kmzFile = !empty($kmzFiles) ? basename($kmzFiles[0]) : null;

            $images = glob($tripPath . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);
            $imageCount = is_array($images) ? count($images) : 0;

            if ($kmzFile || $imageCount > 0) {
                $mtime = $kmzFile ? filemtime($tripPath . '/' . $kmzFile) : filemtime($tripPath);
                $sizeBytes = $kmzFile ? filesize($tripPath . '/' . $kmzFile) : 0;
                $trips[] = [
                    'slug' => $slug,
                    'kmz_path' => $kmzFile ? "trip/$username/$slug/$kmzFile" : null,
                    'image_count' => $imageCount,
                    'size_kb' => round($sizeBytes / 1024, 1),
                    'updated_at' => date('Y-m-d H:i', $mtime),
                    'timestamp' => $mtime
                ];
            }
        }
    }

    usort($trips, function ($a, $b) {
        return $b['timestamp'] <=> $a['timestamp'];
    });

    echo json_encode(['success' => true, 'username' => $username, 'trips' => $trips]);
    exit;
}

if ($action === 'delete') {
    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['slug'] ?? '')));
    if (!$slug) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid trip identifier.']);
        exit;
    }

    $targetDir = $userRoot . '/' . $slug;
    $realUserRoot = realpath($userRoot);
    $realTarget = realpath($targetDir);

    if (!$realUserRoot || !$realTarget || strpos($realTarget, $realUserRoot . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    $files = array_diff(scandir($realTarget), ['.', '..']);
    foreach ($files as $file) {
        @unlink($realTarget . DIRECTORY_SEPARATOR . $file);
    }
    rmdir($realTarget);

    echo json_encode(['success' => true, 'deleted_slug' => $slug]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
