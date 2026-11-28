<?php
/**
 * Configuration loader.
 *
 * Returns a single array with 'db', 'mail' and 'app' sections. See
 * config.sample.php for the expected shape.
 */

function app_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $candidates = [];

    $env = getenv('PHOTOONMAPS_CONFIG');
    if (is_string($env) && $env !== '') {
        $candidates[] = $env;
    }

    // lib/ -> app root -> parent of app root (outside the web root).
    $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'photoonmaps-config.php';
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';

    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException('Config file must return an array: ' . $path);
        }

        $cfg = $loaded;
        $cfg['db'] = ($cfg['db'] ?? []) + [
            'host' => 'localhost',
            'port' => 3306,
            'name' => '',
            'user' => '',
            'pass' => '',
        ];
        $cfg['mail'] = ($cfg['mail'] ?? []) + [
            'transport'  => 'smtp',
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => '',
            'password'   => '',
            'from_email' => '',
            'from_name'  => 'PhotoOnMaps',
        ];
        $cfg['stripe'] = ($cfg['stripe'] ?? []) + [
            'secret_key'         => '',
            'webhook_secret'     => '',
            'subscription_price' => '',
            'lifetime_price'     => '',
            'api_version'        => '2025-03-31.basil',
        ];
        $cfg['plans'] = ($cfg['plans'] ?? []) + [
            'free_storage_bytes' => 1073741824,  // 1 GiB
            'pro_storage_bytes'  => 21474836480, // 20 GiB
            'free_image_bytes'   => 16777216,    // 16 MiB
            'pro_image_bytes'    => 67108864,    // 64 MiB
            'max_kmz_bytes'      => 67108864,    // 64 MiB
        ];
        $cfg['app'] = ($cfg['app'] ?? []) + [
            'base_url'    => '',
            'debug'       => false,
            'trust_proxy' => false,
        ];

        return $cfg;
    }

    throw new RuntimeException(
        'No configuration file found. Copy config.sample.php to config.php, or place '
        . 'photoonmaps-config.php one level above the web root.'
    );
}

/**
 * Absolute base URL of the site, without a trailing slash.
 */
function app_base_url(): string
{
    $configured = trim((string) app_config()['app']['base_url']);
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return ($https ? 'https' : 'http') . '://' . $host;
}
