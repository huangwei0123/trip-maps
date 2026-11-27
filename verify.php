<?php
/**
 * Email confirmation landing page (linked from the verification email).
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/page.php';

boot_session();

$token   = (string) ($_GET['token'] ?? '');
$ok      = false;

if ($token !== '') {
    $row = find_valid_token($token, 'verify');

    if ($row && consume_token((int) $row['id'])) {
        db()->prepare('UPDATE users SET email_verified = 1, updated_at = ? WHERE id = ?')
            ->execute([utc_now(), (int) $row['user_id']]);

        db()->prepare("DELETE FROM auth_tokens WHERE user_id = ? AND purpose = 'verify'")
            ->execute([(int) $row['user_id']]);

        forget_current_user();
        $ok = true;
    }
}

page_start($ok ? 'Email confirmed' : 'Verification link problem');

if ($ok) {
    echo '<h1>Email confirmed</h1>';
    page_message('ok', 'Thanks - your address is confirmed and you can now save trips.');
    echo '<p><a href="/">Open PhotoOnMaps</a></p>';
} else {
    echo '<h1>This link is not valid</h1>';
    page_message('error', 'The confirmation link has expired or has already been used.');
    echo '<p class="hint">Sign in to PhotoOnMaps and use &ldquo;Resend confirmation&rdquo; to get a fresh link.</p>';
    echo '<p><a href="/">Back to PhotoOnMaps</a></p>';
}

page_end();
