<?php
/**
 * Small Stripe REST API client for Checkout and the Customer Portal.
 * Card data is collected only by Stripe-hosted pages; this app never handles it.
 */

require_once __DIR__ . '/bootstrap.php';

function stripe_config(): array
{
    $cfg = app_config()['stripe'];
    if (empty($cfg['secret_key']) || strpos((string) $cfg['secret_key'], 'CHANGE_ME') !== false) {
        throw new RuntimeException('Stripe secret key is not configured.');
    }

    return $cfg;
}

/**
 * Make one authenticated request to Stripe's API.
 */
function stripe_request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Stripe billing.');
    }

    $cfg = stripe_config();
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $headers = [
        'Stripe-Version: ' . $cfg['api_version'],
        'Accept: application/json',
    ];
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => $cfg['secret_key'] . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $method = strtoupper($method);
    if ($method === 'GET') {
        if ($params) {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        }
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($body)) {
        throw new RuntimeException('Could not reach Stripe: ' . ($error ?: 'network error'));
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Stripe returned an invalid response.');
    }
    if ($status < 200 || $status >= 300) {
        $message = $decoded['error']['message'] ?? ('Stripe returned HTTP ' . $status);
        throw new RuntimeException((string) $message);
    }

    return $decoded;
}

/**
 * Verify Stripe's v1 webhook signature using constant-time comparison.
 */
function stripe_verify_webhook(string $payload, string $signatureHeader): bool
{
    $cfg = app_config()['stripe'];
    $secret = (string) ($cfg['webhook_secret'] ?? '');
    if ($secret === '' || strpos($secret, 'CHANGE_ME') !== false) {
        return false;
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) {
            continue;
        }
        if ($pair[0] === 't') {
            $timestamp = ctype_digit($pair[1]) ? (int) $pair[1] : null;
        } elseif ($pair[0] === 'v1') {
            $signatures[] = $pair[1];
        }
    }

    if (!$timestamp || !$signatures || abs(time() - $timestamp) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $provided) {
        if (hash_equals($expected, $provided)) {
            return true;
        }
    }

    return false;
}
