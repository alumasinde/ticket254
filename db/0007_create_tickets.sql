-- 0007_create_tickets.sql
-- Tickets and the per-tenant counter used to allocate ticket numbers.
-- Allocate a number inside the ticket-creation transaction:
--   INSERT INTO ticket_counters (tenant_id, last_number) VALUES (?, 1)
--   ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)
--   then SELECT LAST_INSERT_ID()
-- The human reference is built by the application, for example HD-000123.
--
-- Composite foreign keys keep every relationship inside the same tenant:
--   (tenant_id, site_id), (tenant_id, category_id), (tenant_id, team_id) and
--   (tenant_id, assigned_user_id) which must be a member of that tenant.
-- MySQL does not check a composite foreign key when one of its columns is NULL, which is what we want for optional links.

CREATE TABLE IF NOT EXISTS ticket_counters (
  tenant_id    BIGINT UNSIGNED NOT NULL,
  last_number  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id),
  CONSTRAINT fk_ticket_counters_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          BIGINT UNSIGNED NOT NULL,
  ticket_number      BIGINT UNSIGNED NOT NULL,
  site_id            BIGINT UNSIGNED NULL,
  category_id        BIGINT UNSIGNED NULL,
  requester_user_id  BIGINT UNSIGNED NULL COMMENT 'Only when the requester has an account',
  requester_name     VARCHAR(160)    NOT NULL,
  requester_email    VARCHAR(254)    NOT NULL,
  requester_phone    VARCHAR(32)     NULL,
  team_id            BIGINT UNSIGNED NULL,
  assigned_user_id   BIGINT UNSIGNED NULL,
  subject            VARCHAR(200)    NOT NULL,
  description        TEXT            NOT NULL,
  status_id          BIGINT UNSIGNED NOT NULL,
  priority_id        BIGINT UNSIGNED NOT NULL,
  source             VARCHAR(10)     NOT NULL DEFAULT 'staff' COMMENT 'public or staff',
  version            INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT 'Optimistic locking, bumped on every change',
  due_at             DATETIME        NULL,
  resolved_at        DATETIME        NULL,
  closed_at          DATETIME        NULL,
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tickets_tenant_number (tenant_id, ticket_number),
  UNIQUE KEY uq_tickets_tenant_id (tenant_id, id),
  KEY idx_tickets_status (tenant_id, status_id, created_at),
  KEY idx_tickets_assignee (tenant_id, assigned_user_id, status_id),
  KEY idx_tickets_team (tenant_id, team_id, status_id),
  KEY idx_tickets_created (tenant_id, created_at),
  KEY idx_tickets_requester_email (tenant_id, requester_email),
  KEY idx_tickets_site (tenant_id, site_id),
  KEY idx_tickets_category (tenant_id, category_id),
  KEY idx_tickets_requester_user (requester_user_id),
  KEY idx_tickets_priority (priority_id),
  KEY idx_tickets_status_ref (status_id),
  CONSTRAINT fk_tickets_tenant    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_tickets_site      FOREIGN KEY (tenant_id, site_id)          REFERENCES sites (tenant_id, id),
  CONSTRAINT fk_tickets_category  FOREIGN KEY (tenant_id, category_id)      REFERENCES categories (tenant_id, id),
  CONSTRAINT fk_tickets_team      FOREIGN KEY (tenant_id, team_id)          REFERENCES teams (tenant_id, id),
  CONSTRAINT fk_tickets_assignee  FOREIGN KEY (tenant_id, assigned_user_id) REFERENCES tenant_memberships (tenant_id, user_id),
  CONSTRAINT fk_tickets_requester FOREIGN KEY (requester_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_status    FOREIGN KEY (status_id)   REFERENCES ticket_statuses (id),
  CONSTRAINT fk_tickets_priority  FOREIGN KEY (priority_id) REFERENCES ticket_priorities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
