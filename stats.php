<?php
error_reporting(0);
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/lib/usage.php';

$viewer = usage_normalize_user($_SESSION['username'] ?? '');

if ($viewer === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in to view usage statistics.']);
    exit;
}

$action = $_GET['action'] ?? 'summary';

if ($action === 'summary') {
    $isAdmin = usage_is_admin($viewer);
    $summary = usage_summary($viewer, $isAdmin);

    echo json_encode(array_merge([
        'success' => true,
        'is_admin' => $isAdmin,
    ], $summary));
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
