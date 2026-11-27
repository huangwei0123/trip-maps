<?php
/**
 * Authentication helpers: session identity, CSRF, rate limiting, tokens and
 * folder-slug allocation.
 */

require_once __DIR__ . '/bootstrap.php';

/* ------------------------------------------------------------------ CSRF -- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/**
 * Reject the request unless it carries the session's CSRF token.
 */
function require_csrf(): void
{
    $sent  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    $known = $_SESSION['csrf'] ?? '';

    if (!is_string($sent) || $known === '' || !hash_equals($known, $sent)) {
        json_fail('Your session expired. Reload the page and try again.', 419);
    }
}

/* -------------------------------------------------------------- identity -- */

/**
 * The signed-in user row, or null. Cached for the duration of the request.
 */
function current_user(): ?array
{
    if (array_key_exists('pom_current_user', $GLOBALS)) {
        return $GLOBALS['pom_current_user'];
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return $GLOBALS['pom_current_user'] = null;
    }

    $st = db()->prepare(
        'SELECT id, email, email_norm, password_hash, folder_slug, email_verified, session_version, created_at
           FROM users WHERE id = ? LIMIT 1'
    );
    $st->execute([(int) $userId]);
    $row = $st->fetch();

    if (!$row) {
        unset($_SESSION['user_id'], $_SESSION['session_version']);

        return $GLOBALS['pom_current_user'] = null;
    }

    // A password change (or forced logout) bumps session_version, which
    // invalidates every session that was issued before it.
    if ((int) $row['session_version'] !== (int) ($_SESSION['session_version'] ?? 0)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        return $GLOBALS['pom_current_user'] = null;
    }

    return $GLOBALS['pom_current_user'] = $row;
}

function forget_current_user(): void
{
    unset($GLOBALS['pom_current_user']);
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        json_fail('Please log in to continue.', 401, ['error_code' => 'NOT_LOGGED_IN']);
    }

    return $user;
}

/**
 * Require a signed-in user whose email address has been confirmed.
 */
function require_verified(): array
{
    $user = require_user();
    if ((int) $user['email_verified'] !== 1) {
        json_fail(
            'Please confirm your email address first. Check your inbox for the link, or resend it.',
            403,
            ['error_code' => 'EMAIL_UNVERIFIED']
        );
    }

    return $user;
}

function login_user(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']         = (int) $row['id'];
    $_SESSION['session_version'] = (int) $row['session_version'];
    $_SESSION['csrf']            = bin2hex(random_bytes(32));
    forget_current_user();
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    forget_current_user();
}

function find_user_by_email(string $emailNorm): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE email_norm = ? LIMIT 1');
    $st->execute([$emailNorm]);
    $row = $st->fetch();

    return $row ?: null;
}

/* --------------------------------------------------------- rate limiting -- */

function throttle_hit(string $bucket, string $kind): void
{
    db()->prepare('INSERT INTO auth_throttle (bucket, kind, created_at) VALUES (?, ?, ?)')
        ->execute([substr($bucket, 0, 190), substr($kind, 0, 16), utc_now()]);

    // Opportunistic cleanup keeps the table from growing forever without
    // needing a cron job.
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM auth_throttle WHERE created_at < ?')
            ->execute([utc_after(-172800)]);
    }
}

function throttle_count(string $bucket, string $kind, int $windowSeconds): int
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM auth_throttle WHERE bucket = ? AND kind = ? AND created_at > ?'
    );
    $st->execute([substr($bucket, 0, 190), substr($kind, 0, 16), utc_after(-$windowSeconds)]);

    return (int) $st->fetchColumn();
}

function throttle_guard(string $bucket, string $kind, int $limit, int $windowSeconds): void
{
    if (throttle_count($bucket, $kind, $windowSeconds) >= $limit) {
        json_fail('Too many attempts. Please wait a few minutes and try again.', 429);
    }
}

function throttle_clear(string $bucket, string $kind): void
{
    db()->prepare('DELETE FROM auth_throttle WHERE bucket = ? AND kind = ?')
        ->execute([substr($bucket, 0, 190), substr($kind, 0, 16)]);
}

/* --------------------------------------------------------------- tokens -- */

function ip_binary(): ?string
{
    return client_ip();
}

/**
 * Create a single-use token and return the raw value (only the SHA-256 of it
 * is stored). Any previous unused token for the same purpose is revoked.
 */
function issue_token(int $userId, string $purpose, int $ttlSeconds): string
{
    $raw = bin2hex(random_bytes(32));
    $pdo = db();

    $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND purpose = ?')
        ->execute([$userId, $purpose]);

    $pdo->prepare(
        'INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at, created_at, ip)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $purpose, hash('sha256', $raw), utc_after($ttlSeconds), utc_now(), ip_binary()]);

    return $raw;
}

function find_valid_token(string $raw, string $purpose): ?array
{
    if ($raw === '') {
        return null;
    }

    $st = db()->prepare(
        'SELECT id, user_id, expires_at, used_at FROM auth_tokens
          WHERE token_hash = ? AND purpose = ? LIMIT 1'
    );
    $st->execute([hash('sha256', $raw), $purpose]);
    $row = $st->fetch();

    if (!$row || $row['used_at'] !== null) {
        return null;
    }
    if (strtotime($row['expires_at'] . ' UTC') < time()) {
        return null;
    }

    return $row;
}

/**
 * Mark a token used. Returns false if another request already consumed it.
 */
function consume_token(int $tokenId): bool
{
    $st = db()->prepare('UPDATE auth_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL');
    $st->execute([utc_now(), $tokenId]);

    return $st->rowCount() === 1;
}

function delete_tokens_for_user(int $userId): void
{
    db()->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute([$userId]);
}

/* --------------------------------------------------------- folder slugs -- */

function slugify_local_part(string $emailNorm): string
{
    $local = strstr($emailNorm, '@', true);
    if ($local === false || $local === '') {
        $local = $emailNorm;
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($local));
    $slug = trim((string) $slug, '-');

    return $slug === '' ? 'user' : substr($slug, 0, 40);
}

/**
 * Deterministic, collision-resistant directory name: readable local part plus
 * six hex characters of the email's hash. Two different addresses can never
 * map to the same folder, unlike the old "strip @ and ." scheme.
 */
function build_folder_slug(string $emailNorm): string
{
    return slugify_local_part($emailNorm) . '-' . substr(hash('sha256', $emailNorm), 0, 6);
}

function folder_slug_exists(string $slug): bool
{
    $st = db()->prepare('SELECT 1 FROM users WHERE folder_slug = ? LIMIT 1');
    $st->execute([$slug]);

    return (bool) $st->fetchColumn();
}

function unique_folder_slug(string $emailNorm): string
{
    $slug = build_folder_slug($emailNorm);
    if (!folder_slug_exists($slug)) {
        return $slug;
    }

    for ($i = 1; $i <= 20; $i++) {
        $candidate = $slug . $i;
        if (!folder_slug_exists($candidate)) {
            return $candidate;
        }
    }

    return $slug . '-' . bin2hex(random_bytes(3));
}

function audit(string $message): void
{
    error_log('[photoonmaps] ' . $message);
}
