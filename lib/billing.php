<?php
/**
 * Billing entitlements and storage quota helpers.
 */

require_once __DIR__ . '/bootstrap.php';

function billing_entitlement(array $user): array
{
    $status = (string) ($user['stripe_subscription_status'] ?? '');
    $subscriptionActive = in_array($status, ['active', 'trialing'], true);
    $lifetime = (int) ($user['lifetime_pro'] ?? 0) === 1;
    $isPro = $lifetime || $subscriptionActive;
    $plans = app_config()['plans'];

    return [
        'plan' => $isPro ? 'pro' : 'free',
        'is_pro' => $isPro,
        'lifetime_pro' => $lifetime,
        'subscription_status' => $status !== '' ? $status : null,
        'subscription_active' => $subscriptionActive,
        'stripe_customer' => !empty($user['stripe_customer_id']),
        'storage_quota_bytes' => (int) ($isPro ? $plans['pro_storage_bytes'] : $plans['free_storage_bytes']),
        'image_max_bytes' => (int) ($isPro ? $plans['pro_image_bytes'] : $plans['free_image_bytes']),
        'kmz_max_bytes' => (int) $plans['max_kmz_bytes'],
    ];
}

/**
 * Count user-uploaded content. The per-trip index.html and quota lock are
 * implementation files, not customer storage, so they do not count.
 */
function user_storage_bytes(string $folderSlug): int
{
    if (!preg_match('/^[a-z0-9_-]{1,64}$/', $folderSlug)) {
        throw new InvalidArgumentException('Invalid storage folder identifier.');
    }

    $root = dirname(__DIR__) . '/trip/' . $folderSlug;
    if (!is_dir($root)) {
        return 0;
    }

    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $name = $entry->getFilename();
        if ($name === 'index.html' || $name === '.quota.lock') {
            continue;
        }

        $size = $entry->getSize();
        if ($size > 0) {
            $bytes += $size;
        }
    }

    return $bytes;
}

/**
 * Get a per-user exclusive filesystem lock. Keep the returned handle open
 * until after the upload has been written so concurrent requests cannot
 * overshoot the user's quota.
 *
 * @return resource
 */
function lock_user_storage(string $folderSlug)
{
    if (!preg_match('/^[a-z0-9_-]{1,64}$/', $folderSlug)) {
        throw new InvalidArgumentException('Invalid storage folder identifier.');
    }

    $root = dirname(__DIR__) . '/trip/' . $folderSlug;
    if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create the user storage directory.');
    }

    $handle = @fopen($root . '/.quota.lock', 'c');
    if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Could not lock the user storage directory.');
    }

    return $handle;
}

function unlock_user_storage($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function human_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) max(0, $bytes);
    $unit = 0;

    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
}
