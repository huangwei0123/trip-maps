<?php
session_start();
header('Content-Type: application/json');

$usersDir = __DIR__ . '/users';
if (!is_dir($usersDir)) {
    mkdir($usersDir, 0755, true);
    // Block direct browser access to password hashes
    file_put_contents($usersDir . '/.htaccess', "Deny from all\n");
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'status') {
    if (!empty($_SESSION['username'])) {
        echo json_encode(['logged_in' => true, 'username' => $_SESSION['username']]);
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

$rawUser = $_POST['username'] ?? '';
$username = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($rawUser)));
$password = $_POST['password'] ?? '';

if (strlen($username) < 3 || strlen($username) > 30) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username must be 3–30 characters (letters, numbers, _, -).']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']);
    exit;
}

$userFile = $usersDir . '/' . $username . '.json';

if ($action === 'register') {
    if (file_exists($userFile)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Username is already taken.']);
        exit;
    }

    $userData = [
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('c')
    ];

    if (file_put_contents($userFile, json_encode($userData, JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not create user account.']);
        exit;
    }

    // Create the user's dedicated /trip/<username> directory
    $userTripRoot = __DIR__ . '/trip/' . $username;
    if (!is_dir($userTripRoot)) {
        mkdir($userTripRoot, 0755, true);
        file_put_contents($userTripRoot . '/index.html', '<!doctype html><title>User Trip Directory</title>');
    }

    session_regenerate_id(true);
    $_SESSION['username'] = $username;

    echo json_encode(['success' => true, 'username' => $username]);
    exit;
}

if ($action === 'login') {
    if (!file_exists($userFile)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid username or password.']);
        exit;
    }

    $userData = json_decode(file_get_contents($userFile), true);
    if (!$userData || !password_verify($password, $userData['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid username or password.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['username'] = $username;

    echo json_encode(['success' => true, 'username' => $username]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid auth action.']);
