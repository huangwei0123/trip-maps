<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/lib/usage.php';

function emailToFolderSlug(string $email): string {
    $slug = str_replace('@', '_at_', strtolower(trim($email)));
    $slug = preg_replace('/[^a-z0-9_-]/', '_',$slug);
    $slug = preg_replace('/_+/', '_',$slug);
    return trim($slug, '_');
}

if (empty($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$rawFolder = $_SESSION['folder_slug'] ?? emailToFolderSlug($_SESSION['username']);
$folderSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($rawFolder));
if ($folderSlug === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user directory identifier.']);
    exit;
}

$userRoot = __DIR__ . '/trip/' .$folderSlug;
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'list') {$trips = [];
    if (is_dir($userRoot)) {
        $subDirs = scandir($userRoot);
        foreach ($subDirs as$slug) {
            if ($slug === '.' ||$slug === '..') continue;
            $tripPath = $userRoot . '/' .$slug;
            if (!is_dir($tripPath)) continue;

            $kmzFiles = glob($tripPath . '/*.kmz');$kmzFile = !empty($kmzFiles) ? basename($kmzFiles[0]) : null;

            $images = glob($tripPath . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);$imageCount = is_array($images) ? count($images) : 0;

            if ($kmzFile || $imageCount > 0) {$mtime = $kmzFile ? filemtime($tripPath . '/' . $kmzFile) : filemtime($tripPath);
                $sizeBytes =$kmzFile ? filesize($tripPath . '/' .$kmzFile) : 0;
                $trips[] = [
                    'slug' => $slug,
                    'kmz_path' => $kmzFile ? "trip/$folderSlug/$slug/$kmzFile" : null,
                    'image_count' => $imageCount,
                    'size_kb' => round($sizeBytes / 1024, 1), 'updated_at' => date('Y-m-d H:i',$mtime),
                    'timestamp' => $mtime
                ];
            }
        }
    }

    usort($trips, function ($a,$b) {
        return $b['timestamp'] <=>$a['timestamp'];
    });

    echo json_encode([
        'success' => true,
        'username' => $_SESSION['username'],
        'folder_slug' => $folderSlug,
        'trips' => $trips
    ]);
    exit;
}

if ($action === 'delete') {
    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['slug'] ?? '')));
    if (!$slug) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid trip identifier.']);
        exit;
    }

    $targetDir = $userRoot . '/' .$slug;
    $realUserRoot = realpath($userRoot);
    $realTarget = realpath($targetDir);

    if (!$realUserRoot || !$realTarget || strpos($realTarget,$realUserRoot . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    $files = array_diff(scandir($realTarget), ['.', '..']);
    $freedBytes = 0;
    $freedFiles = 0;
    foreach ($files as $file) {
        $filePath = $realTarget . DIRECTORY_SEPARATOR . $file;
        if (is_file($filePath)) {
            $freedBytes += (int) @filesize($filePath);
            $freedFiles++;
        }
        @unlink($filePath);
    }
    rmdir($realTarget);

    usage_log('trip_deleted', $_SESSION['username'], [
        'trip' => $slug,
        'bytes' => $freedBytes,
        'files' => $freedFiles,
    ]);

    echo json_encode(['success' => true, 'deleted_slug' => $slug]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
