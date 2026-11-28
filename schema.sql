-- PhotoOnMaps authentication schema.
-- MySQL 5.7+ / 8.0, InnoDB. Import through phpMyAdmin or:
--   mysql -u USER -p DBNAME < schema.sql
--
-- Notes:
--   * email_norm is VARCHAR(190) so the UNIQUE index fits inside the 767-byte
--     index limit of older InnoDB row formats (190 * 4 bytes = 760).
--   * All DATETIME values are written as UTC by PHP; never use NOW().

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(254) NOT NULL,
    email_norm      VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    folder_slug     VARCHAR(64)  NOT NULL,
    email_verified  TINYINT(1)   NOT NULL DEFAULT 0,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    stripe_customer_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    stripe_subscription_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    stripe_lifetime_payment_intent VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    stripe_subscription_status VARCHAR(32) NULL DEFAULT NULL,
    stripe_period_end DATETIME NULL DEFAULT NULL,
    stripe_subscription_event_created BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lifetime_pro TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    UNIQUE KEY uq_email  (email_norm),
    UNIQUE KEY uq_folder (folder_slug),
    UNIQUE KEY uq_stripe_customer (stripe_customer_id),
    UNIQUE KEY uq_stripe_subscription (stripe_subscription_id),
    UNIQUE KEY uq_stripe_lifetime_payment (stripe_lifetime_payment_intent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_events (
    event_id    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    event_type  VARCHAR(100) NOT NULL,
    related_payment_intent VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    fully_refunded TINYINT(1) NULL DEFAULT NULL,
    created_at  DATETIME NOT NULL,
    processed_at DATETIME NOT NULL,
    KEY idx_stripe_refund (related_payment_intent, fully_refunded)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    purpose    ENUM('verify','reset') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    ip         VARCHAR(45) NULL DEFAULT NULL,
    UNIQUE KEY uq_token (token_hash),
    KEY idx_user_purpose (user_id, purpose),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_throttle (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket     VARCHAR(190) NOT NULL,
    kind       VARCHAR(16)  NOT NULL,
    created_at DATETIME     NOT NULL,
    KEY idx_lookup (bucket, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
