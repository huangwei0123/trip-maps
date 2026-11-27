<?php
/**
 * PhotoOnMaps configuration template.
 *
 * Copy this file to `config.php` in the project root for local development, or
 * (recommended for production) place it ONE LEVEL ABOVE the web root as
 * `photoonmaps-config.php`:
 *
 *   /home/youruser/photoonmaps-config.php     <-- not reachable over HTTP
 *   /home/youruser/public_html/auth.php       <-- app files
 *
 * The loader checks, in order:
 *   1. $PHOTOONMAPS_CONFIG environment variable
 *   2. <parent of app dir>/photoonmaps-config.php
 *   3. <app dir>/config.php
 *
 * Never commit the real config.php: it is listed in .gitignore.
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'photoonmaps',
        'user' => 'photoonmaps_user',
        'pass' => 'CHANGE_ME',
    ],

    'mail' => [
        // 'smtp' uses the built-in SMTP client (recommended).
        // 'mail' falls back to PHP mail() and is only for local testing.
        'transport'  => 'smtp',
        'host'       => 'smtp-relay.brevo.com',
        'port'       => 587,
        'encryption' => 'tls',   // 'tls' = STARTTLS on 587, 'ssl' = implicit TLS on 465, '' = none
        'username'   => 'CHANGE_ME',
        'password'   => 'CHANGE_ME',
        'from_email' => 'no-reply@photoonmaps.com',
        'from_name'  => 'PhotoOnMaps',
    ],

    'app' => [
        // Used to build verification / reset links. No trailing slash.
        'base_url' => 'https://www.photoonmaps.com',

        // Show PHP errors in the browser. Keep false in production.
        'debug' => false,

        // Set true only if the site sits behind a reverse proxy you control
        // (Cloudflare, etc.). When false, rate limiting uses REMOTE_ADDR and
        // cannot be bypassed by forging X-Forwarded-For.
        'trust_proxy' => false,
    ],
];
