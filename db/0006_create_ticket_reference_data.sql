-- 0006_create_ticket_reference_data.sql
-- Global ticket statuses and priorities, and per-tenant categories.
-- is_terminal means work is finished, so the ticket no longer counts as overdue.
-- Allowed status transitions live in the application (TicketLifecycle), not in the database.

CREATE TABLE IF NOT EXISTS ticket_statuses (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(30)     NOT NULL,
  label        VARCHAR(60)     NOT NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_terminal  TINYINT(1)      NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_statuses_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_priorities (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(30)     NOT NULL,
  label          VARCHAR(60)     NOT NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  default_hours  INT UNSIGNED    NOT NULL COMMENT 'Hours until due_at when a ticket is created with this priority',
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_priorities_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id            BIGINT UNSIGNED NOT NULL,
  name                 VARCHAR(120)    NOT NULL,
  default_priority_id  BIGINT UNSIGNED NULL,
  team_id              BIGINT UNSIGNED NULL COMMENT 'New tickets in this category are routed to this team',
  status               VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active, archived',
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_tenant_id (tenant_id, id),
  UNIQUE KEY uq_categories_tenant_name (tenant_id, name),
  KEY idx_categories_team (tenant_id, team_id),
  KEY idx_categories_priority (default_priority_id),
  CONSTRAINT fk_categories_tenant   FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_categories_team     FOREIGN KEY (tenant_id, team_id) REFERENCES teams (tenant_id, id),
  CONSTRAINT fk_categories_priority FOREIGN KEY (default_priority_id) REFERENCES ticket_priorities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ticket_statuses (id, code, label, sort_order, is_terminal) VALUES
  (1, 'open',        'Open',        1, 0),
  (2, 'assigned',    'Assigned',    2, 0),
  (3, 'in_progress', 'In progress', 3, 0),
  (4, 'pending',     'Pending',     4, 0),
  (5, 'resolved',    'Resolved',    5, 1),
  (6, 'closed',      'Closed',      6, 1);

INSERT IGNORE INTO ticket_priorities (id, code, label, sort_order, default_hours) VALUES
  (1, 'low',    'Low',    1, 72),
  (2, 'normal', 'Normal', 2, 24),
  (3, 'high',   'High',   3, 8),
  (4, 'urgent', 'Urgent', 4, 4);
