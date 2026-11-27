<?php
/**
 * Password reset page, opened from the emailed link.
 *
 * Deliberately works without JavaScript: the token arrives in the URL, the
 * form posts back to this same page.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/password.php';
require_once __DIR__ . '/lib/page.php';
require_once __DIR__ . '/lib/usage.php';

boot_session();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$row   = find_valid_token($token, 'reset');

if (!$row) {
    page_start('Reset link expired');
    echo '<h1>Link expired or already used</h1>';
    page_message('error', 'This reset link is no longer valid. Request a new one from the sign-in window.');
    echo '<p><a href="/">Back to PhotoOnMaps</a></p>';
    page_end();
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    $st = db()->prepare('SELECT id, email, email_norm, folder_slug, session_version FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int) $row['user_id']]);
    $user = $st->fetch();

    if (!$user) {
        $error = 'That account no longer exists.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $problems = password_problems($password, $user['email_norm']);
        if ($problems) {
            $error = implode(' ', $problems);
        } elseif (password_breach_count($password) > 0) {
            $error = 'That password has appeared in a known data breach. Please choose a different one.';
        }
    }

    if ($error === '') {
        $newVersion = (int) $user['session_version'] + 1;
        $pdo        = db();

        try {
            $pdo->beginTransaction();

            // Claim the token atomically so a double submit cannot win twice.
            if (!consume_token((int) $row['id'])) {
                throw new RuntimeException('This reset link was already used.');
            }

            $pdo->prepare(
                'UPDATE users
                    SET password_hash = ?, session_version = ?, email_verified = 1, updated_at = ?
                  WHERE id = ?'
            )->execute([password_hash_new($password), $newVersion, utc_now(), (int) $user['id']]);

            // Revoke every outstanding link for this account.
            $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute([(int) $user['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            audit('Password reset failed: ' . $e->getMessage());
            $error = 'We could not save the new password. Please request a fresh link.';
        }

        if ($error === '') {
            // Using the emailed link proves control of the mailbox, so sign in.
            login_user(['id' => (int) $user['id'], 'session_version' => $newVersion]);

            usage_log('password_reset', (string) $user['folder_slug'], ['via' => 'reset_link']);
            usage_log('login', (string) $user['folder_slug'], ['via' => 'reset_link']);

            page_start('Password updated');
            echo '<h1>Password updated</h1>';
            page_message('ok', 'Your password has been changed and other devices have been signed out.');
            echo '<p><a href="/">Open PhotoOnMaps</a></p>';
            page_end();
            exit;
        }
    }
}

page_start('Choose a new password');
echo '<h1>Choose a new password</h1>';

if ($error !== '') {
    page_message('error', $error);
}

?>
<form method="post" action="reset.php" autocomplete="off">
  <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

  <label for="password">New password</label>
  <input type="password" id="password" name="password" required minlength="10" maxlength="128"
         autocomplete="new-password" autofocus>

  <label for="confirm">Confirm new password</label>
  <input type="password" id="confirm" name="confirm" required minlength="10" maxlength="128"
         autocomplete="new-password">

  <p class="hint" style="margin-top:12px;">
    At least 10 characters. Avoid passwords you use on other sites.
  </p>

  <button type="submit">Save new password</button>
</form>
<?php
page_end();
