<?php
/**
 * List and delete trips inside the signed-in user's folder.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

boot_session();

$user = require_user();

$folderSlug = (string) $user['folder_slug'];
if (!preg_match('/^[a-z0-9_-]{1,64}$/', $folderSlug)) {
    json_fail('Invalid user directory identifier.', 400);
}

$userRoot = __DIR__ . '/trip/' . $folderSlug;
$action   = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'list') {
    $trips = [];

    if (is_dir($userRoot)) {
        $subDirs = scandir($userRoot) ?: [];
        foreach ($subDirs as $slug) {
            if ($slug === '.' || $slug === '..') {
                continue;
            }

            $tripPath = $userRoot . '/' . $slug;
            if (!is_dir($tripPath)) {
                continue;
            }

            $kmzFiles = glob($tripPath . '/*.kmz') ?: [];
            $kmzFile  = !empty($kmzFiles) ? basename($kmzFiles[0]) : null;

            $images     = glob($tripPath . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [];
            $imageCount = count($images);

            if ($kmzFile || $imageCount > 0) {
                $mtime     = $kmzFile ? filemtime($tripPath . '/' . $kmzFile) : filemtime($tripPath);
                $sizeBytes = $kmzFile ? filesize($tripPath . '/' . $kmzFile) : 0;

                $trips[] = [
                    'slug'        => $slug,
                    'kmz_path'    => $kmzFile ? "trip/$folderSlug/$slug/$kmzFile" : null,
                    'image_count' => $imageCount,
                    'size_kb'     => round($sizeBytes / 1024, 1),
                    'updated_at'  => date('Y-m-d H:i', $mtime),
                    'timestamp'   => $mtime,
                ];
            }
        }
    }

    usort($trips, function ($a, $b) {
        return $b['timestamp'] <=> $a['timestamp'];
    });

    json_out([
        'success'     => true,
        'email'       => $user['email'],
        'folder_slug' => $folderSlug,
        'trips'       => $trips,
    ]);
}

if ($action === 'delete') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_fail('Method not allowed.', 405);
    }
    require_csrf();

    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string) ($_POST['slug'] ?? ''))));
    if (!$slug) {
        json_fail('Invalid trip identifier.');
    }

    $targetDir    = $userRoot . '/' . $slug;
    $realUserRoot = realpath($userRoot);
    $realTarget   = realpath($targetDir);

    if (!$realUserRoot || !$realTarget
        || strpos($realTarget, $realUserRoot . DIRECTORY_SEPARATOR) !== 0) {
        json_fail('Access denied.', 403);
    }

    $files = array_diff(scandir($realTarget) ?: [], ['.', '..']);
    foreach ($files as $file) {
        @unlink($realTarget . DIRECTORY_SEPARATOR . $file);
    }
    @rmdir($realTarget);

    json_out(['success' => true, 'deleted_slug' => $slug]);
}

json_fail('Invalid action.');
