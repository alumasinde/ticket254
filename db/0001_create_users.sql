-- 0001_create_users.sql
-- Global user accounts. A user can belong to many workspaces through tenant_memberships.
-- Platform administrators are users with is_platform_admin = 1 (no workspace role needed).
-- All DATETIME values are UTC. The application sets the session time zone to +00:00 on connect.

CREATE TABLE IF NOT EXISTS users (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name         VARCHAR(100)    NOT NULL,
  last_name          VARCHAR(100)    NOT NULL,
  email              VARCHAR(254)    NOT NULL COMMENT 'Stored trimmed and lowercased by the application',
  phone              VARCHAR(32)     NULL,
  password_hash      VARCHAR(255)    NOT NULL,
  status             VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active, disabled',
  is_platform_admin  TINYINT(1)      NOT NULL DEFAULT 0,
  locale             VARCHAR(5)      NOT NULL DEFAULT 'en' COMMENT 'en now, sw later',
  email_verified_at  DATETIME        NULL,
  last_login_at      DATETIME        NULL,
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
