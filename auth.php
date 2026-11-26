<?php
error_reporting(0);
session_start();
header('Content-Type: application/json');

function emailToFolderSlug(string $email): string {
    $slug = str_replace('@', '_at_', strtolower(trim($email)));
    $slug = preg_replace('/[^a-z0-9_-]/', '_',$slug);
    $slug = preg_replace('/_+/', '_',$slug);
    return trim($slug, '_');
}

$usersDir = __DIR__ . '/users';
if (!is_dir($usersDir)) {
    @mkdir($usersDir, 0755, true);
    @file_put_contents($usersDir . '/.htaccess', "Deny from all\n");
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'status') {
    if (!empty($_SESSION['username'])) {$folderSlug = $_SESSION['folder_slug'] ?? emailToFolderSlug($_SESSION['username']);
        echo json_encode([
            'logged_in' => true,
            'username' => $_SESSION['username'],
            'folder_slug' => $folderSlug
        ]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

$rawEmail = $_POST['username'] ?? $_POST['email'] ?? '';
$email = strtolower(trim($rawEmail));
$password =$_POST['password'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']);
    exit;
}

$folderSlug = emailToFolderSlug($email);$userFile = $usersDir . '/' .$folderSlug . '.json';

if ($action === 'register') {
    if (file_exists($userFile)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'An account with this email already exists.']);
        exit;
    }

    $userData = [
        'username' => $email,
        'email' => $email,
        'folder_slug' => $folderSlug,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('c')
    ];

    if (@file_put_contents($userFile, json_encode($userData, JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server permission error creating account in /users/.']);
        exit;
    }

    $userTripRoot = __DIR__ . '/trip/' .$folderSlug;
    if (!is_dir($userTripRoot)) {
        @mkdir($userTripRoot, 0755, true);
        @file_put_contents($userTripRoot . '/index.html', '<!doctype html><title>User Trip Directory</title>');
    }

    session_regenerate_id(true);
    $_SESSION['username'] =$email;
    $_SESSION['folder_slug'] =$folderSlug;

    echo json_encode([
        'success' => true,
        'username' => $email,
        'folder_slug' => $folderSlug
    ]);
    exit;
}

if ($action === 'login') {
    if (!file_exists($userFile)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Account not found. Click Register first if you have not created it yet.']);
        exit;
    }

    $userData = json_decode(file_get_contents($userFile), true);
    if (!$userData || !password_verify($password,$userData['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid email or password.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['username'] =$email;
    $_SESSION['folder_slug'] = $userData['folder_slug'] ?? $folderSlug;

    echo json_encode([
        'success' => true,
        'username' => $email,
        'folder_slug' => $_SESSION['folder_slug']
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid auth action.']);
