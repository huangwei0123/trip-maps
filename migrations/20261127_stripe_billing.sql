-- Apply once to an existing PhotoOnMaps database before deploying billing:
--   mysql -u USER -p DBNAME < migrations/20261127_stripe_billing.sql
--
-- Fresh databases get these columns and the event table from schema.sql.

ALTER TABLE users
    ADD COLUMN stripe_customer_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    ADD COLUMN stripe_subscription_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    ADD COLUMN stripe_lifetime_payment_intent VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    ADD COLUMN stripe_subscription_status VARCHAR(32) NULL DEFAULT NULL,
    ADD COLUMN stripe_period_end DATETIME NULL DEFAULT NULL,
    ADD COLUMN stripe_subscription_event_created BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN lifetime_pro TINYINT(1) NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uq_stripe_customer (stripe_customer_id),
    ADD UNIQUE KEY uq_stripe_subscription (stripe_subscription_id),
    ADD UNIQUE KEY uq_stripe_lifetime_payment (stripe_lifetime_payment_intent);

CREATE TABLE stripe_events (
    event_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    related_payment_intent VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    fully_refunded TINYINT(1) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    processed_at DATETIME NOT NULL,
    KEY idx_stripe_refund (related_payment_intent, fully_refunded)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
