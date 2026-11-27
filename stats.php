<?php
/**
 * Usage dashboard API: per-user login times, storage used and share links.
 *
 * Requires a signed-in session. Admins (stats/admins.json or the
 * PHOTOONMAPS_ADMINS env var, both listing folder slugs) see every account;
 * everyone else sees only their own data.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/usage.php';

boot_session();

$action = (string) ($_GET['action'] ?? 'summary');

if ($action === 'summary') {
    $user = current_user();
    if (!$user) {
        json_fail('Please log in to view usage statistics.', 401);
    }

    $viewer  = (string) $user['folder_slug'];
    $isAdmin = usage_is_admin($viewer);

    $accounts = [];
    if ($isAdmin) {
        $st = db()->query('SELECT email, folder_slug, created_at FROM users ORDER BY created_at ASC');
        foreach ($st->fetchAll() as $row) {
            $accounts[] = [
                'slug'       => (string) $row['folder_slug'],
                'email'      => (string) $row['email'],
                'created_at' => (string) $row['created_at'],
            ];
        }
    } else {
        $accounts[] = [
            'slug'       => $viewer,
            'email'      => (string) $user['email'],
            'created_at' => (string) $user['created_at'],
        ];
    }

    $summary = usage_summary($viewer, $isAdmin, $accounts);

    json_out(['success' => true, 'is_admin' => $isAdmin] + $summary);
}

json_fail('Invalid action.');
