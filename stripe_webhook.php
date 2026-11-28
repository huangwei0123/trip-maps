<?php
/**
 * Stripe webhook endpoint. Configure this URL in Stripe Dashboard:
 * https://www.photoonmaps.com/stripe_webhook.php
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/stripe.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$payload = file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if (!is_string($payload) || strlen($payload) > 1024 * 1024
    || !stripe_verify_webhook($payload, $signature)) {
    http_response_code(400);
    echo 'Invalid webhook signature.';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
    http_response_code(400);
    echo 'Invalid webhook payload.';
    exit;
}

$eventId = (string) $event['id'];
$eventType = (string) $event['type'];
$eventCreated = (int) ($event['created'] ?? time());
$object = $event['data']['object'] ?? [];

if (!is_array($object)) {
    http_response_code(400);
    echo 'Invalid event object.';
    exit;
}

$pdo = null;
try {
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $relatedPaymentIntent = null;
        $fullyRefunded = null;
        if ($eventType === 'charge.refunded') {
            $relatedPaymentIntent = isset($object['payment_intent']) && is_string($object['payment_intent'])
                ? $object['payment_intent']
                : null;
            $fullyRefunded = !empty($object['refunded']) ? 1 : 0;
        }
        $pdo->prepare(
            'INSERT INTO stripe_events
                (event_id, event_type, related_payment_intent, fully_refunded, created_at, processed_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $eventId,
            $eventType,
            $relatedPaymentIntent,
            $fullyRefunded,
            gmdate('Y-m-d H:i:s', $eventCreated),
            utc_now(),
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $pdo->rollBack();
            http_response_code(200);
            echo 'Already processed.';
            exit;
        }
        throw $e;
    }

    if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
        $userId = (int) ($object['client_reference_id'] ?? $object['metadata']['user_id'] ?? 0);
        if ($userId > 0) {
            $customerId = isset($object['customer']) && is_string($object['customer'])
                ? $object['customer']
                : null;
            $offering = (string) ($object['metadata']['offering'] ?? '');

            if ($customerId !== null) {
                $pdo->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ?')
                    ->execute([$customerId, $userId]);
            }

            if (($object['mode'] ?? '') === 'payment'
                && ($object['payment_status'] ?? '') === 'paid'
                && $offering === 'lifetime') {
                $paymentIntent = isset($object['payment_intent']) && is_string($object['payment_intent'])
                    ? $object['payment_intent']
                    : null;
                $alreadyFullyRefunded = false;
                if ($paymentIntent !== null) {
                    $refundCheck = $pdo->prepare(
                        'SELECT 1 FROM stripe_events
                          WHERE event_type = ? AND related_payment_intent = ? AND fully_refunded = 1
                          LIMIT 1'
                    );
                    $refundCheck->execute(['charge.refunded', $paymentIntent]);
                    $alreadyFullyRefunded = (bool) $refundCheck->fetchColumn();
                }
                if (!$alreadyFullyRefunded) {
                    $pdo->prepare(
                        'UPDATE users
                            SET lifetime_pro = 1,
                                stripe_lifetime_payment_intent = COALESCE(?, stripe_lifetime_payment_intent),
                                updated_at = ?
                          WHERE id = ?'
                    )->execute([$paymentIntent, utc_now(), $userId]);
                }
            }
        }
    }

    if ($eventType === 'charge.refunded' && !empty($object['refunded']) && !empty($object['payment_intent'])) {
        $pdo->prepare(
            'UPDATE users
                SET lifetime_pro = 0, stripe_lifetime_payment_intent = NULL, updated_at = ?
              WHERE stripe_lifetime_payment_intent = ?'
        )->execute([utc_now(), (string) $object['payment_intent']]);
    }

    if (in_array($eventType, [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ], true)) {
        $metadataUserId = (int) ($object['metadata']['user_id'] ?? 0);
        $customerId = isset($object['customer']) && is_string($object['customer'])
            ? $object['customer']
            : null;
        $user = null;

        if ($metadataUserId > 0) {
            $select = $pdo->prepare(
                'SELECT id, stripe_subscription_id, stripe_subscription_status, stripe_subscription_event_created
                   FROM users WHERE id = ? FOR UPDATE'
            );
            $select->execute([$metadataUserId]);
            $user = $select->fetch();
        }
        if (!$user && $customerId !== null) {
            $select = $pdo->prepare(
                'SELECT id, stripe_subscription_id, stripe_subscription_status, stripe_subscription_event_created
                   FROM users WHERE stripe_customer_id = ? FOR UPDATE'
            );
            $select->execute([$customerId]);
            $user = $select->fetch();
        }

        $incomingSubscriptionId = (string) ($object['id'] ?? '');
        $currentSubscriptionIsActive = in_array(
            (string) ($user['stripe_subscription_status'] ?? ''),
            ['active', 'trialing'],
            true
        );
        $isDifferentActiveSubscription = $user
            && !empty($user['stripe_subscription_id'])
            && $user['stripe_subscription_id'] !== $incomingSubscriptionId
            && $currentSubscriptionIsActive;

        if ($user && !$isDifferentActiveSubscription
            && $eventCreated >= (int) $user['stripe_subscription_event_created']) {
            $periodEnd = null;
            if (!empty($object['current_period_end'])) {
                $periodEnd = gmdate('Y-m-d H:i:s', (int) $object['current_period_end']);
            } elseif (!empty($object['items']['data'][0]['current_period_end'])) {
                $periodEnd = gmdate('Y-m-d H:i:s', (int) $object['items']['data'][0]['current_period_end']);
            }

            $pdo->prepare(
                'UPDATE users
                    SET stripe_customer_id = COALESCE(?, stripe_customer_id),
                        stripe_subscription_id = ?,
                        stripe_subscription_status = ?,
                        stripe_period_end = ?,
                        stripe_subscription_event_created = ?,
                        updated_at = ?
                  WHERE id = ?'
            )->execute([
                $customerId,
                $incomingSubscriptionId,
                (string) ($object['status'] ?? 'canceled'),
                $periodEnd,
                $eventCreated,
                utc_now(),
                (int) $user['id'],
            ]);
        }
    }

    $pdo->commit();
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    audit('Stripe webhook failed for ' . $eventId . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'Webhook processing failed.';
}
