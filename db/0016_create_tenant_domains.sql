-- 0016_create_tenant_domains.sql
-- Custom public domains such as helpdesk.gleenairobi.com (post-pilot feature, safe to apply now).
-- Only public pages are served on these domains. Staff sign-in stays on the main app domain.

CREATE TABLE IF NOT EXISTS tenant_domains (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           BIGINT UNSIGNED NOT NULL,
  domain              VARCHAR(253)    NOT NULL COMMENT 'Lowercase hostname',
  status              VARCHAR(24)     NOT NULL DEFAULT 'pending_verification' COMMENT 'pending_verification, active, failed',
  verification_token  CHAR(32)        NOT NULL,
  verified_at         DATETIME        NULL,
  ssl_status          VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending, issued, failed',
  last_checked_at     DATETIME        NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenant_domains_domain (domain),
  KEY idx_tenant_domains_tenant (tenant_id),
  KEY idx_tenant_domains_status (status),
  CONSTRAINT fk_tenant_domains_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
