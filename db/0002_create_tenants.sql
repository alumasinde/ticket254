-- 0002_create_tenants.sql
-- Workspaces (one per business) and the history of their old public slugs.
-- slug is the readable public form identifier, for example /request/gleehotel.
-- public_key is the random identifier used when public_form_mode = 'private'.

CREATE TABLE IF NOT EXISTS tenants (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(160)    NOT NULL,
  slug                  VARCHAR(100)    NOT NULL,
  public_key            VARCHAR(32)     NOT NULL COMMENT 'Random, used in private-link mode and rotated on demand',
  public_form_mode      VARCHAR(10)     NOT NULL DEFAULT 'slug' COMMENT 'slug or private',
  public_form_enabled   TINYINT(1)      NOT NULL DEFAULT 1,
  status                VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active, suspended, closed',
  timezone              VARCHAR(64)     NOT NULL DEFAULT 'Africa/Nairobi',
  contact_email         VARCHAR(254)    NULL,
  contact_phone         VARCHAR(32)     NULL,
  settings_json         JSON            NULL COMMENT 'allowed_email_domains, allow_requester_replies, attachments_enabled',
  suspended_at          DATETIME        NULL,
  suspended_reason      VARCHAR(255)    NULL,
  created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenants_slug (slug),
  UNIQUE KEY uq_tenants_public_key (public_key),
  KEY idx_tenants_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slug_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  old_slug    VARCHAR(100)    NOT NULL COMMENT 'Redirects to the current slug',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug_history_old_slug (old_slug),
  KEY idx_slug_history_tenant (tenant_id),
  CONSTRAINT fk_slug_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
