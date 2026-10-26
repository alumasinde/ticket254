-- 0012_create_audit_logs.sql
-- Security and administrative events: logins, role changes, member disabled, slug or public link changes,
-- tenant suspended, support access. tenant_id is NULL for platform-level events.
-- Keep personal data out of metadata. Rows are append-only.

CREATE TABLE IF NOT EXISTS audit_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NULL,
  actor_user_id  BIGINT UNSIGNED NULL,
  action         VARCHAR(80)     NOT NULL,
  resource_type  VARCHAR(60)     NOT NULL,
  resource_id    VARCHAR(64)     NULL,
  ip_address     VARCHAR(45)     NULL,
  user_agent     VARCHAR(255)    NULL,
  metadata       JSON            NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_tenant (tenant_id, created_at),
  KEY idx_audit_actor (actor_user_id, created_at),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_resource (resource_type, resource_id),
  CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_audit_actor  FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
