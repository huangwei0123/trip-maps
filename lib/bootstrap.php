<?php
/**
 * Shared bootstrap: configuration, database, error handling, JSON helpers and
 * session setup. Every entry point (auth.php, verify.php, reset.php,
 * save_trip.php, my_trips.php) starts by requiring this file.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/db.php';

// All timestamps are stored as UTC so PHP and MySQL can never disagree about
// "now". Every comparison is done with a PHP-generated value, never NOW().
date_default_timezone_set('UTC');

$__app = app_config()['app'];

error_reporting(E_ALL);
ini_set('display_errors', $__app['debug'] ? '1' : '0');
ini_set('log_errors', '1');

/**
 * Send a JSON response and stop.
 */
function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error and stop.
 */
function json_fail(string $message, int $status = 400, array $extra = []): void
{
    json_out(['success' => false, 'error' => $message] + $extra, $status);
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    return app_config()['app']['trust_proxy']
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * Start the session with hardened cookie settings. Safe to call repeatedly.
 */
function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('pom_session');
    session_start();
}

/**
 * Client IP used for rate limiting.
 *
 * X-Forwarded-For is deliberately ignored unless 'trust_proxy' is enabled,
 * because it is trivially forged and would let anyone bypass throttling.
 */
function client_ip(): string
{
    if (app_config()['app']['trust_proxy']) {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Lowercase an email without requiring mbstring.
 */
function normalize_email(string $email): string
{
    $email = trim($email);

    return function_exists('mb_strtolower')
        ? mb_strtolower($email, 'UTF-8')
        : strtolower($email);
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function utc_after(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}
