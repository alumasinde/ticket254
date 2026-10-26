-- 0004_create_memberships.sql
-- Who belongs to which workspace, with which role, and pending invitations.
-- The composite primary key (tenant_id, user_id) is also referenced by other tables
-- so that team members and ticket assignees are guaranteed to be members of the same tenant.

CREATE TABLE IF NOT EXISTS tenant_memberships (
  tenant_id   BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  role_id     BIGINT UNSIGNED NOT NULL,
  status      VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active, disabled',
  joined_at   DATETIME        NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, user_id),
  KEY idx_memberships_user (user_id),
  KEY idx_memberships_role (role_id),
  KEY idx_memberships_status (tenant_id, status),
  CONSTRAINT fk_memberships_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_memberships_user   FOREIGN KEY (user_id)   REFERENCES users (id),
  CONSTRAINT fk_memberships_role   FOREIGN KEY (role_id)   REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  email        VARCHAR(254)    NOT NULL,
  role_id      BIGINT UNSIGNED NOT NULL,
  team_ids     JSON            NULL COMMENT 'Teams to join on acceptance, for example [2,5]',
  token_hash   CHAR(64)        NOT NULL COMMENT 'SHA-256 of the emailed token',
  invited_by   BIGINT UNSIGNED NULL,
  expires_at   DATETIME        NOT NULL,
  accepted_at  DATETIME        NULL,
  revoked_at   DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invitations_token (token_hash),
  KEY idx_invitations_tenant_email (tenant_id, email),
  KEY idx_invitations_role (role_id),
  KEY idx_invitations_invited_by (invited_by),
  CONSTRAINT fk_invitations_tenant FOREIGN KEY (tenant_id)  REFERENCES tenants (id),
  CONSTRAINT fk_invitations_role   FOREIGN KEY (role_id)    REFERENCES roles (id),
  CONSTRAINT fk_invitations_user   FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
