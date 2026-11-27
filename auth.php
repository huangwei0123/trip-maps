<?php
error_reporting(0);
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/lib/usage.php';

$usersDir = __DIR__ . '/users';
if (!is_dir($usersDir)) {
    @mkdir($usersDir, 0755, true);
    @file_put_contents($usersDir . '/.htaccess', "Deny from all\n");
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
    usage_log('logout', $_SESSION['username'] ?? '');
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

$rawUser = $_POST['username'] ?? ($_SESSION['username'] ?? '');
$username = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($rawUser)));
$password = $_POST['password'] ?? '';

if (strlen($username) < 3 || strlen($username) > 30) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username must be 3–30 characters (letters, numbers, _, -).']);
    exit;
}

$userFile = $usersDir . '/' . $username . '.json';

if ($action === 'register') {
    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']);
        exit;
    }

    if (file_exists($userFile)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Username is already taken.']);
        exit;
    }

    $recoveryKey = strtoupper(bin2hex(random_bytes(4))); // e.g., A3F902C1

    $userData = [
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'recovery_hash' => password_hash($recoveryKey, PASSWORD_DEFAULT),
        'created_at' => date('c')
    ];

    if (@file_put_contents($userFile, json_encode($userData, JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server permission error creating account in /users/.']);
        exit;
    }

    $userTripRoot = __DIR__ . '/trip/' . $username;
    if (!is_dir($userTripRoot)) {
        @mkdir($userTripRoot, 0755, true);
        @file_put_contents($userTripRoot . '/index.html', '<!doctype html><title>User Trip Directory</title>');
    }

    session_regenerate_id(true);
    $_SESSION['username'] = $username;

    usage_log('register', $username);
    usage_log('login', $username, ['via' => 'register']);

    echo json_encode([
        'success' => true,
        'username' => $username,
        'recovery_key' => $recoveryKey
    ]);
    exit;
}

if ($action === 'login') {
    if (strlen($password) < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please enter your password.']);
        exit;
    }

    if (!file_exists($userFile)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error_code' => 'USER_NOT_FOUND',
            'error' => 'No account found with that username. Click Register to create one.'
        ]);
        exit;
    }

    $userData = json_decode(file_get_contents($userFile), true);
    if (!$userData || !password_verify($password, $userData['password_hash'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error_code' => 'WRONG_PASSWORD',
            'error' => 'Incorrect password. Please try again or click "Reset Password" below.'
        ]);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['username'] = $username;

    usage_log('login', $username);

    echo json_encode(['success' => true, 'username' => $username]);
    exit;
}

if ($action === 'reset_password') {
    $newPassword = $_POST['new_password'] ?? '';
    $recoveryKey = strtoupper(trim($_POST['recovery_key'] ?? ''));

    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters.']);
        exit;
    }

    if (!file_exists($userFile)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Account not found.']);
        exit;
    }

    $userData = json_decode(file_get_contents($userFile), true);
    $isLoggedInOwner = (!empty($_SESSION['username']) && $_SESSION['username'] === $username);
    $hasValidRecovery = (!empty($userData['recovery_hash']) && !empty($recoveryKey) && password_verify($recoveryKey, $userData['recovery_hash']));

    if (!$isLoggedInOwner && !$hasValidRecovery) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid Recovery Key. Please enter the 8-character key shown when you registered.'
        ]);
        exit;
    }

    // Generate a fresh recovery key on reset so the user always has an active backup key
    $nextRecoveryKey = strtoupper(bin2hex(random_bytes(4)));
    $userData['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    $userData['recovery_hash'] = password_hash($nextRecoveryKey, PASSWORD_DEFAULT);
    $userData['updated_at'] = date('c');

    if (@file_put_contents($userFile, json_encode($userData, JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save new password.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['username'] = $username;

    usage_log('password_reset', $username);
    usage_log('login', $username, ['via' => 'password_reset']);

    echo json_encode([
        'success' => true,
        'username' => $username,
        'recovery_key' => $nextRecoveryKey
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid auth action.']);
