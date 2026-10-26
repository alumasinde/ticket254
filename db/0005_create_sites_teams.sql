-- 0005_create_sites_teams.sql
-- Branches or buildings (sites), teams, and team membership.
-- UNIQUE (tenant_id, id) lets other tables use composite foreign keys such as (tenant_id, team_id),
-- so a row can never point at another tenant's team or site.

CREATE TABLE IF NOT EXISTS sites (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120)    NOT NULL,
  address     VARCHAR(255)    NULL,
  status      VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active, archived',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sites_tenant_id (tenant_id, id),
  UNIQUE KEY uq_sites_tenant_name (tenant_id, name),
  CONSTRAINT fk_sites_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120)    NOT NULL,
  site_id     BIGINT UNSIGNED NULL,
  status      VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active, archived',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teams_tenant_id (tenant_id, id),
  UNIQUE KEY uq_teams_tenant_name (tenant_id, name),
  KEY idx_teams_site (tenant_id, site_id),
  CONSTRAINT fk_teams_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_teams_site   FOREIGN KEY (tenant_id, site_id) REFERENCES sites (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_members (
  tenant_id   BIGINT UNSIGNED NOT NULL,
  team_id     BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, team_id, user_id),
  KEY idx_team_members_user (tenant_id, user_id),
  CONSTRAINT fk_team_members_team   FOREIGN KEY (tenant_id, team_id) REFERENCES teams (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_team_members_member FOREIGN KEY (tenant_id, user_id) REFERENCES tenant_memberships (tenant_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
