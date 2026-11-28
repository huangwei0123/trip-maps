# Stripe billing setup

This branch adds Stripe-hosted checkout for a recurring Pro plan and a
one-time Lifetime Pro purchase. Card information is collected by Stripe;
PhotoOnMaps stores only Stripe customer/subscription identifiers and plan
entitlements.

## Plan limits

Defaults are configurable in `config.php` under `plans`:

| Plan | Total saved storage | KMZ upload | Single image upload |
|---|---:|---:|---:|
| Free | 1 GiB | 64 MiB | 16 MiB |
| Pro subscription | 20 GiB | 64 MiB | 64 MiB |
| Lifetime Pro | 20 GiB | 64 MiB | 64 MiB |

Lifetime Pro is granted only after Stripe confirms the one-time payment in a
signed webhook. A full refund of the lifetime payment removes that entitlement.
Subscription access is active while Stripe reports `active` or `trialing`; a
cancellation or other non-active state removes Pro limits. Stripe cancellation
at period end normally leaves the subscription active through its paid period.

Usage counts files saved under `trip/<account-folder>/`, excluding generated
`index.html` files. A per-account filesystem lock prevents simultaneous uploads
from bypassing quota checks.

## 1. Create Stripe products and prices

In Stripe Dashboard, first use **Test mode**:

1. Create a **PhotoOnMaps Pro** recurring price. Pick monthly or annual billing.
   The app accepts one recurring price ID; Stripe Checkout displays its actual
   interval and price.
2. Create a **PhotoOnMaps Lifetime Pro** one-time price.
3. Copy the two `price_...` IDs.
4. Open **Settings → Billing → Customer portal** and enable subscription
   cancellation and payment-method updates.

No price amount is hard-coded in the app. Set the real prices in Stripe.

## 2. Configure private keys

Use test-mode credentials for local testing. Add them to the private config
file—not to the repository or a message:

```php
'stripe' => [
    'secret_key'         => 'sk_test_...',
    'webhook_secret'     => 'whsec_...',
    'subscription_price' => 'price_...',
    'lifetime_price'     => 'price_...',
    'api_version'        => '2025-03-31.basil',
],
```

For production, use the live-mode secret and live-mode Price IDs, and place the
config file outside `public_html` as described in `AUTH-SETUP.md`.

PHP cURL must be enabled. The purchase flow uses Stripe Checkout and the
Customer Portal; the app never accepts card numbers.

## 3. Apply the database migration

For a new database, import the updated `schema.sql`.

For a database that already has PhotoOnMaps authentication tables, apply this
migration exactly once before deploying the billing code:

```powershell
cmd /c "mariadb -u root -p photoonmaps < migrations\20261127_stripe_billing.sql"
```

The migration adds Stripe IDs, subscription state, Lifetime Pro entitlement,
and the webhook idempotency table. Back up the database first.

## 4. Configure the webhook

Create a Stripe webhook endpoint for:

```text
https://www.photoonmaps.com/stripe_webhook.php
```

Subscribe to these events:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `charge.refunded`

Copy the endpoint's signing secret (`whsec_...`) into the private config.
Stripe retries non-2xx responses; the app verifies signatures, records event IDs
and safely ignores duplicate deliveries.

For local testing, use the Stripe CLI to forward test events to your local PHP
server (for example, `stripe listen --forward-to
localhost:8000/stripe_webhook.php`) and put the CLI's generated webhook secret
in the local config.

## 5. Test the complete flow

1. Register and confirm an email address.
2. Open **Plans & Storage**. Confirm the free quota and current usage display.
3. In test mode, use Stripe's documented test card numbers and complete the
   subscription checkout.
4. Confirm the webhook has arrived, reload the plan panel, and verify Pro limits
   are active. Do not treat the success redirect alone as proof of payment.
5. Test Customer Portal cancellation. Once Stripe marks the subscription
   inactive, the account should revert to Free.
6. Test a one-time Lifetime Pro checkout and a full refund.
7. Test free and Pro image size caps and total-storage limits.
8. Confirm PHP `upload_max_filesize` and `post_max_size` on HostGator allow the
   configured per-file sizes; application limits cannot override server limits.

The code is not ready for live payments until real Stripe products, keys,
webhook configuration, database migration, and a test-mode end-to-end run are
complete.
