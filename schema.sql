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
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    UNIQUE KEY uq_email  (email_norm),
    UNIQUE KEY uq_folder (folder_slug)
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
