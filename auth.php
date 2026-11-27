<?php
/**
 * Auth API: status, register, login, logout, forgot, change_password,
 * resend_verification.
 *
 * Password *reset* from an emailed link is handled by reset.php, and email
 * confirmation by verify.php, so both work without JavaScript.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/password.php';
require_once __DIR__ . '/lib/mailer.php';

boot_session();

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

$postOnly = ['register', 'login', 'logout', 'forgot', 'change_password', 'resend_verification'];
if (in_array($action, $postOnly, true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Method not allowed.', 405);
}

try {
    switch ($action) {

        /* ------------------------------------------------------- status -- */
        case 'status': {
            $user = current_user();

            json_out([
                'success'        => true,
                'logged_in'      => $user !== null,
                'email'          => $user['email'] ?? null,
                'folder_slug'    => $user['folder_slug'] ?? null,
                'email_verified' => $user ? (int) $user['email_verified'] === 1 : false,
                'csrf'           => csrf_token(),
            ]);
        }

        /* ----------------------------------------------------- register -- */
        case 'register': {
            require_csrf();
            throttle_guard('ip:' . client_ip(), 'register', 5, 3600);
            throttle_hit('ip:' . client_ip(), 'register');

            $email    = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_fail('Please enter a valid email address.');
            }

            $emailNorm = normalize_email($email);
            if (strlen($emailNorm) > 190) {
                json_fail('Please use an email address no longer than 190 bytes.');
            }

            $problems = password_problems($password, $emailNorm);
            if ($problems) {
                json_fail(implode(' ', $problems));
            }
            if (password_breach_count($password) > 0) {
                json_fail('That password has appeared in a known data breach. Please choose a different one.');
            }

            // Identical response whether or not the address is new, so the
            // endpoint cannot be used to enumerate accounts.
            $generic = 'Check your inbox to finish creating your account. The link expires in 24 hours.';

            $existing = find_user_by_email($emailNorm);
            if ($existing) {
                // Re-registering an unverified address with the same password
                // just re-sends the confirmation link.
                if ((int) $existing['email_verified'] === 0
                    && password_matches($password, $existing['password_hash'])) {
                    $token = issue_token((int) $existing['id'], 'verify', 86400);
                    send_verification_email($existing['email'], $token);
                }

                json_out([
                    'success' => true,
                    'logged_in' => false,
                    'message' => $generic,
                    'csrf' => csrf_token(),
                ]);
            }

            $hash     = password_hash_new($password);
            $inserted = false;
            $userId   = 0;

            for ($attempt = 0; $attempt < 3 && !$inserted; $attempt++) {
                $slug = unique_folder_slug($emailNorm);

                try {
                    db()->prepare(
                        'INSERT INTO users
                            (email, email_norm, password_hash, folder_slug, email_verified, session_version, created_at, updated_at)
                         VALUES (?, ?, ?, ?, 0, 1, ?, ?)'
                    )->execute([$email, $emailNorm, $hash, $slug, utc_now(), utc_now()]);

                    $userId   = (int) db()->lastInsertId();
                    $inserted = true;
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                    if (find_user_by_email($emailNorm)) {
                        json_out(['success' => true, 'message' => $generic]);
                    }
                    // Otherwise it was a folder-slug collision: retry.
                }
            }

            if (!$inserted) {
                json_fail('Could not create the account right now. Please try again.', 500);
            }

            $token = issue_token($userId, 'verify', 86400);
            send_verification_email($email, $token);

            // Sign them in so the app can show a "confirm your email" banner.
            login_user(['id' => $userId, 'session_version' => 1]);

            json_out([
                'success'        => true,
                'message'        => $generic,
                'logged_in'      => true,
                'email'          => $email,
                'folder_slug'    => $slug,
                'email_verified' => false,
                'csrf'           => csrf_token(),
            ]);
        }

        /* -------------------------------------------------------- login -- */
        case 'login': {
            require_csrf();

            $email    = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $emailNorm = normalize_email($email);

            $ipBucket    = 'ip:' . client_ip();
            $emailBucket = 'email:' . hash('sha256', $emailNorm);

            throttle_guard($ipBucket, 'login', 20, 900);
            if ($emailNorm !== '') {
                throttle_guard($emailBucket, 'login', 5, 900);
            }

            $user = $emailNorm === '' ? null : find_user_by_email($emailNorm);

            if (!$user) {
                // Spend comparable time so a missing account is not obvious.
                password_matches($password, TIMING_DUMMY_HASH);
            }

            if (!$user || !password_matches($password, $user['password_hash'])) {
                throttle_hit($ipBucket, 'login');
                if ($emailNorm !== '') {
                    throttle_hit($emailBucket, 'login');
                }
                json_fail('Email or password is incorrect.', 401, ['error_code' => 'BAD_CREDENTIALS']);
            }

            if (password_needs_upgrade($user['password_hash'])) {
                $upgraded = password_hash_new($password);
                db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
                    ->execute([$upgraded, utc_now(), (int) $user['id']]);
                $user['password_hash'] = $upgraded;
            }

            throttle_clear($emailBucket, 'login');
            login_user($user);

            json_out([
                'success'        => true,
                'logged_in'      => true,
                'email'          => $user['email'],
                'folder_slug'    => $user['folder_slug'],
                'email_verified' => (int) $user['email_verified'] === 1,
                'csrf'           => csrf_token(),
            ]);
        }

        /* ------------------------------------------------------- logout -- */
        case 'logout': {
            require_csrf();
            logout_user();
            json_out(['success' => true]);
        }

        /* ------------------------------------------------------- forgot -- */
        case 'forgot': {
            require_csrf();
            throttle_guard('ip:' . client_ip(), 'forgot', 10, 3600);

            $emailNorm = normalize_email((string) ($_POST['email'] ?? ''));
            if ($emailNorm === '' || !filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
                json_fail('Please enter a valid email address.');
            }

            $emailBucket = 'email:' . hash('sha256', $emailNorm);
            throttle_guard($emailBucket, 'forgot', 3, 3600);
            throttle_hit('ip:' . client_ip(), 'forgot');
            throttle_hit($emailBucket, 'forgot');

            $user = find_user_by_email($emailNorm);
            if ($user) {
                $token = issue_token((int) $user['id'], 'reset', 3600);
                send_reset_email($user['email'], $token);
            }

            json_out([
                'success' => true,
                'message' => 'If that address has an account, we just sent a password reset link. It expires in one hour.',
            ]);
        }

        /* ---------------------------------------------- change_password -- */
        case 'change_password': {
            $user = require_user();
            require_csrf();
            throttle_guard('ip:' . client_ip(), 'change_pw', 10, 3600);

            $current = (string) ($_POST['current_password'] ?? '');
            $new     = (string) ($_POST['new_password'] ?? '');

            if (!password_matches($current, $user['password_hash'])) {
                throttle_hit('ip:' . client_ip(), 'change_pw');
                json_fail('Your current password is incorrect.', 401);
            }

            $problems = password_problems($new, $user['email_norm']);
            if ($problems) {
                json_fail(implode(' ', $problems));
            }
            if (password_breach_count($new) > 0) {
                json_fail('That password has appeared in a known data breach. Please choose a different one.');
            }

            $newVersion = (int) $user['session_version'] + 1;

            db()->prepare(
                'UPDATE users SET password_hash = ?, session_version = ?, updated_at = ? WHERE id = ?'
            )->execute([password_hash_new($new), $newVersion, utc_now(), (int) $user['id']]);

            delete_tokens_for_user((int) $user['id']);

            // Keep this session alive, invalidate every other one.
            $_SESSION['session_version'] = $newVersion;
            forget_current_user();

            json_out(['success' => true, 'message' => 'Password updated. Other devices have been signed out.']);
        }

        /* ----------------------------------------- resend_verification -- */
        case 'resend_verification': {
            $user = require_user();
            require_csrf();

            if ((int) $user['email_verified'] === 1) {
                json_out(['success' => true, 'message' => 'Your email address is already confirmed.']);
            }

            throttle_guard('ip:' . client_ip(), 'resend', 5, 3600);
            throttle_hit('ip:' . client_ip(), 'resend');

            $token = issue_token((int) $user['id'], 'verify', 86400);
            send_verification_email($user['email'], $token);

            json_out(['success' => true, 'message' => 'Confirmation email sent. Check your inbox.']);
        }

        default:
            json_fail('Unknown action.', 400);
    }
} catch (Throwable $e) {
    audit('Unhandled auth error: ' . $e->getMessage());
    json_fail('Something went wrong on our side. Please try again in a moment.', 500);
}
