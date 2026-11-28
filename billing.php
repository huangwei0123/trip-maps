<?php
/**
 * Authenticated billing API: status, hosted Stripe Checkout, and Customer Portal.
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/stripe.php';

boot_session();

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if (in_array($action, ['checkout', 'portal'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Method not allowed.', 405);
}

try {
    if ($action === 'status') {
        $user = require_user();
        $entitlement = billing_entitlement($user);
        $used = user_storage_bytes((string) $user['folder_slug']);
        $entitlement['storage_used_bytes'] = $used;
        $entitlement['storage_remaining_bytes'] = max(0, $entitlement['storage_quota_bytes'] - $used);
        $entitlement['storage_quota_label'] = human_bytes($entitlement['storage_quota_bytes']);
        $entitlement['storage_used_label'] = human_bytes($used);
        $entitlement['image_max_label'] = human_bytes($entitlement['image_max_bytes']);
        $entitlement['kmz_max_label'] = human_bytes($entitlement['kmz_max_bytes']);
        $entitlement['period_end_label'] = !empty($user['stripe_period_end'])
            ? gmdate('Y-m-d', strtotime($user['stripe_period_end'] . ' UTC'))
            : null;
        $stripe = app_config()['stripe'];
        $secretReady = !empty($stripe['secret_key'])
            && strpos((string) $stripe['secret_key'], 'CHANGE_ME') === false;
        $entitlement['checkout_subscription_ready'] = $secretReady
            && !empty($stripe['subscription_price'])
            && strpos((string) $stripe['subscription_price'], 'CHANGE_ME') === false;
        $entitlement['checkout_lifetime_ready'] = $secretReady
            && !empty($stripe['lifetime_price'])
            && strpos((string) $stripe['lifetime_price'], 'CHANGE_ME') === false;

        json_out(['success' => true, 'billing' => $entitlement, 'csrf' => csrf_token()]);
    }

    if ($action === 'checkout') {
        $user = require_verified();
        require_csrf();
        throttle_guard('user:' . (int) $user['id'], 'checkout', 10, 3600);
        throttle_hit('user:' . (int) $user['id'], 'checkout');

        $offering = (string) ($_POST['offering'] ?? '');
        if (!in_array($offering, ['subscription', 'lifetime'], true)) {
            json_fail('Choose a valid Pro purchase option.');
        }

        $entitlement = billing_entitlement($user);
        if ($offering === 'lifetime' && $entitlement['lifetime_pro']) {
            json_fail('Lifetime Pro is already active on your account.', 409);
        }
        if ($offering === 'subscription' && $entitlement['subscription_active']) {
            json_fail('Your Pro subscription is already active. Use Manage Billing to update it.', 409);
        }

        $cfg = stripe_config();
        $price = (string) ($offering === 'subscription'
            ? $cfg['subscription_price']
            : $cfg['lifetime_price']);
        if ($price === '' || strpos($price, 'CHANGE_ME') !== false) {
            throw new RuntimeException('The Stripe Price ID for this purchase is not configured.');
        }

        $mode = $offering === 'subscription' ? 'subscription' : 'payment';
        $userId = (string) $user['id'];
        $params = [
            'mode' => $mode,
            'line_items[0][price]' => $price,
            'line_items[0][quantity]' => '1',
            'client_reference_id' => $userId,
            'success_url' => app_base_url() . '/?billing=success',
            'cancel_url' => app_base_url() . '/?billing=cancelled',
            'allow_promotion_codes' => 'true',
            'metadata[user_id]' => $userId,
            'metadata[offering]' => $offering,
        ];

        if (!empty($user['stripe_customer_id'])) {
            $params['customer'] = $user['stripe_customer_id'];
        } else {
            $params['customer_email'] = $user['email'];
            if ($mode === 'payment') {
                $params['customer_creation'] = 'always';
            }
        }

        if ($mode === 'subscription') {
            $params['subscription_data[metadata][user_id]'] = $userId;
            $params['subscription_data[metadata][offering]'] = $offering;
        } else {
            $params['payment_intent_data[metadata][user_id]'] = $userId;
            $params['payment_intent_data[metadata][offering]'] = $offering;
        }

        $checkout = stripe_request(
            'POST',
            'checkout/sessions',
            $params,
            'pom-checkout-' . $userId . '-' . $offering . '-' . bin2hex(random_bytes(12))
        );

        if (empty($checkout['url']) || !filter_var($checkout['url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Stripe did not return a Checkout URL.');
        }

        json_out(['success' => true, 'url' => $checkout['url']]);
    }

    if ($action === 'portal') {
        $user = require_user();
        require_csrf();
        throttle_guard('user:' . (int) $user['id'], 'portal', 20, 3600);
        throttle_hit('user:' . (int) $user['id'], 'portal');

        if (empty($user['stripe_customer_id'])) {
            json_fail('Billing details are not available yet. If you just paid, wait a moment and refresh.', 409);
        }

        $portal = stripe_request('POST', 'billing_portal/sessions', [
            'customer' => $user['stripe_customer_id'],
            'return_url' => app_base_url() . '/',
        ]);

        if (empty($portal['url']) || !filter_var($portal['url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Stripe did not return a Billing Portal URL.');
        }

        json_out(['success' => true, 'url' => $portal['url']]);
    }

    json_fail('Unknown billing action.');
} catch (Throwable $e) {
    audit('Billing API error: ' . $e->getMessage());
    json_fail('Billing is temporarily unavailable. Please try again later.', 503);
}
