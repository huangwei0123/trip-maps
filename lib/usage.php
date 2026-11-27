<?php
/**
 * Usage tracking for PhotoOnMaps.
 *
 * Events are appended to stats/events.jsonl as one JSON object per line.
 * The stats/ directory is protected from direct web access via .htaccess.
 *
 * Tracked events: register, login, logout, password_reset, link_created, trip_deleted.
 * Storage and trip counts are read from the trip/<user>/ directories on demand.
 */

function usage_root(): string
{
    return dirname(__DIR__);
}

function usage_stats_dir(): string
{
    $dir = usage_root() . '/stats';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents(
            $dir . '/.htaccess',
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
        );
        @file_put_contents($dir . '/index.html', '<!doctype html><title>Usage Stats</title>');
    }
    return $dir;
}

function usage_normalize_user($username): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string) $username)));
}

/**
 * Append one usage event. Never throws: tracking must not break the auth/save flow.
 */
function usage_log(string $event, $username = null, array $meta = []): bool
{
    $event = preg_replace('/[^a-z0-9_]/', '', strtolower($event));
    if ($event === '') {
        return false;
    }

    if ($username === null) {
        $username = $_SESSION['username'] ?? '';
    }
    $username = usage_normalize_user($username);
    if ($username === '') {
        return false;
    }

    $record = ['ts' => date('c'), 'user' => $username, 'event' => $event];
    foreach ($meta as $key => $value) {
        if (is_string($key) && (is_scalar($value) || $value === null)) {
            $record[$key] = $value;
        }
    }

    $line = json_encode($record, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return false;
    }

    $file = usage_stats_dir() . '/events.jsonl';
    $ok = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
    if ($ok) {
        usage_rotate_if_needed($file);
    }
    return $ok;
}

/**
 * Keep events.jsonl bounded so the log cannot grow without limit.
 */
function usage_rotate_if_needed(string $file, int $maxBytes = 2097152, int $keepLines = 5000)
{
    $size = @filesize($file);
    if ($size === false || $size <= $maxBytes) {
        return;
    }

    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    $lines = array_slice($lines, -$keepLines);
    @file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX);
}

/**
 * Read recorded events, oldest first.
 */
function usage_read_events(int $limit = 5000): array
{
    $file = usage_stats_dir() . '/events.jsonl';
    if (!is_file($file)) {
        return [];
    }

    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    if ($limit > 0 && count($lines) > $limit) {
        $lines = array_slice($lines, -$limit);
    }

    $events = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && !empty($row['ts']) && !empty($row['user']) && !empty($row['event'])) {
            $events[] = $row;
        }
    }
    return $events;
}

/**
 * Recursively total the files inside a directory.
 */
function usage_dir_metrics(string $path): array
{
    $metrics = ['bytes' => 0, 'files' => 0, 'images' => 0];
    if (!is_dir($path)) {
        return $metrics;
    }

    $entries = @scandir($path);
    if (!is_array($entries)) {
        return $metrics;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full)) {
            $sub = usage_dir_metrics($full);
            $metrics['bytes'] += $sub['bytes'];
            $metrics['files'] += $sub['files'];
            $metrics['images'] += $sub['images'];
        } elseif (is_file($full)) {
            $size = @filesize($full);
            if ($size !== false) {
                $metrics['bytes'] += $size;
            }
            $metrics['files']++;
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $metrics['images']++;
            }
        }
    }

    return $metrics;
}

/**
 * Storage and trip totals for one user folder (trip/<username>/).
 */
function usage_user_metrics(string $folderSlug): array
{
    $folderSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower($folderSlug));
    $metrics = ['bytes' => 0, 'files' => 0, 'images' => 0, 'trips' => 0, 'links' => 0];
    if ($folderSlug === '') {
        return $metrics;
    }

    $root = usage_root() . '/trip/' . $folderSlug;
    if (!is_dir($root)) {
        return $metrics;
    }

    $entries = @scandir($root);
    if (!is_array($entries)) {
        return $metrics;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $tripPath = $root . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($tripPath)) {
            continue;
        }

        $sub = usage_dir_metrics($tripPath);
        if ($sub['files'] === 0) {
            continue;
        }

        // Each trip folder is one shareable link (/trip/<user>/<trip>/).
        $metrics['trips']++;
        $metrics['links']++;
        $metrics['bytes'] += $sub['bytes'];
        $metrics['files'] += $sub['files'];
        $metrics['images'] += $sub['images'];
    }

    return $metrics;
}

function usage_human_bytes($bytes): string
{
    $bytes = max(0, (float) $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    $formatted = $index === 0
        ? (string) (int) $bytes
        : number_format($bytes, $bytes >= 10 ? 0 : 1);

    return $formatted . ' ' . $units[$index];
}

/**
 * Admins are configured either with the PHOTOONMAPS_ADMINS environment
 * variable (comma separated) or stats/admins.json (["alice", "bob"]).
 */
function usage_is_admin(string $username): bool
{
    $username = usage_normalize_user($username);
    if ($username === '') {
        return false;
    }

    $admins = [];

    $env = getenv('PHOTOONMAPS_ADMINS');
    if (is_string($env) && trim($env) !== '') {
        foreach (explode(',', $env) as $entry) {
            $admins[] = usage_normalize_user($entry);
        }
    }

    $file = usage_stats_dir() . '/admins.json';
    if (is_file($file)) {
        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data)) {
            if (isset($data['admins']) && is_array($data['admins'])) {
                $data = $data['admins'];
            }
            foreach ($data as $entry) {
                if (is_string($entry)) {
                    $admins[] = usage_normalize_user($entry);
                }
            }
        }
    }

    return in_array($username, array_filter($admins), true);
}

/**
 * Build the dashboard payload.
 *
 * @param bool  $includeAll false => only the viewer's own data
 * @param array $accounts   list of ['slug' =>, 'email' =>, 'created_at' =>]
 *                          from the users table (all users for admins, just
 *                          the viewer otherwise)
 */
function usage_summary(string $viewer, bool $includeAll, array $accounts = []): array
{
    $viewer = usage_normalize_user($viewer);
    $events = usage_read_events(5000);

    $aggregates = [];
    foreach ($events as $event) {
        $user = usage_normalize_user($event['user']);
        if ($user === '') {
            continue;
        }

        if (!isset($aggregates[$user])) {
            $aggregates[$user] = [
                'first_seen' => null,
                'last_seen' => null,
                'login_count' => 0,
                'last_login' => null,
                'links_created' => 0,
                'last_link_at' => null,
                'event_count' => 0,
            ];
        }

        $agg = &$aggregates[$user];
        $ts = (string) $event['ts'];
        $agg['event_count']++;

        if ($agg['first_seen'] === null || strcmp($ts, $agg['first_seen']) < 0) {
            $agg['first_seen'] = $ts;
        }
        if ($agg['last_seen'] === null || strcmp($ts, $agg['last_seen']) > 0) {
            $agg['last_seen'] = $ts;
        }

        if ($event['event'] === 'login') {
            $agg['login_count']++;
            if ($agg['last_login'] === null || strcmp($ts, $agg['last_login']) > 0) {
                $agg['last_login'] = $ts;
            }
        } elseif ($event['event'] === 'link_created') {
            $agg['links_created']++;
            if ($agg['last_link_at'] === null || strcmp($ts, $agg['last_link_at']) > 0) {
                $agg['last_link_at'] = $ts;
            }
        }
        unset($agg);
    }

    $accountMap = [];
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $slug = usage_normalize_user($account['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $accountMap[$slug] = [
            'email'      => isset($account['email']) ? (string) $account['email'] : null,
            'created_at' => isset($account['created_at']) ? (string) $account['created_at'] : null,
        ];
    }

    if (!isset($accountMap[$viewer])) {
        $accountMap[$viewer] = ['email' => null, 'created_at' => null];
    }

    if ($includeAll) {
        // Keep users whose accounts were deleted but still have events.
        foreach (array_keys($aggregates) as $user) {
            if (!isset($accountMap[$user])) {
                $accountMap[$user] = ['email' => null, 'created_at' => null];
            }
        }
    }

    $emptyAgg = [
        'first_seen' => null,
        'last_seen' => null,
        'login_count' => 0,
        'last_login' => null,
        'links_created' => 0,
        'last_link_at' => null,
        'event_count' => 0,
    ];

    $rows = [];
    foreach ($accountMap as $slug => $info) {
        $agg = isset($aggregates[$slug]) ? $aggregates[$slug] : $emptyAgg;
        $metrics = usage_user_metrics($slug);

        $rows[] = [
            'username' => $slug,
            'email' => $info['email'],
            'created_at' => $info['created_at'],
            'last_login' => $agg['last_login'],
            'login_count' => $agg['login_count'],
            'links_created' => $agg['links_created'],
            'last_link_at' => $agg['last_link_at'],
            'last_seen' => $agg['last_seen'],
            'first_seen' => $agg['first_seen'],
            'event_count' => $agg['event_count'],
            'storage_bytes' => $metrics['bytes'],
            'storage_human' => usage_human_bytes($metrics['bytes']),
            'trips' => $metrics['trips'],
            'links_on_disk' => $metrics['links'],
            'images' => $metrics['images'],
            'files' => $metrics['files'],
        ];
    }

    usort($rows, function ($a, $b) {
        $left = $a['last_seen'] ?: ($a['created_at'] ?: '');
        $right = $b['last_seen'] ?: ($b['created_at'] ?: '');
        if ($left === $right) {
            return strcmp($a['username'], $b['username']);
        }
        return strcmp($right, $left);
    });

    $totals = [
        'users' => count($rows),
        'logins' => 0,
        'links' => 0,
        'storage_bytes' => 0,
        'trips' => 0,
        'images' => 0,
    ];
    foreach ($rows as $row) {
        $totals['logins'] += $row['login_count'];
        $totals['links'] += $row['links_created'];
        $totals['storage_bytes'] += $row['storage_bytes'];
        $totals['trips'] += $row['trips'];
        $totals['images'] += $row['images'];
    }
    $totals['storage_human'] = usage_human_bytes($totals['storage_bytes']);

    $recent = [];
    foreach ($events as $event) {
        $eventUser = usage_normalize_user($event['user']);
        if (!$includeAll && $eventUser !== $viewer) {
            continue;
        }

        $row = [
            'ts' => $event['ts'],
            'user' => $eventUser,
            'event' => $event['event'],
        ];
        foreach (['trip', 'mode', 'path', 'bytes', 'photos', 'files', 'via'] as $key) {
            if (isset($event[$key])) {
                $row[$key] = $event[$key];
            }
        }
        if (isset($row['bytes'])) {
            $row['bytes_human'] = usage_human_bytes($row['bytes']);
        }
        $recent[] = $row;
    }
    $recent = array_reverse(array_slice($recent, -40));

    return [
        'generated_at' => date('c'),
        'viewer' => $viewer,
        'scope' => $includeAll ? 'all' : 'self',
        'totals' => $totals,
        'users' => $rows,
        'recent' => $recent,
    ];
}
