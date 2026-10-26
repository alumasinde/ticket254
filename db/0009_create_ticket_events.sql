-- 0009_create_ticket_events.sql
-- History of changes on a ticket: created, status_changed, assigned, priority_changed,
-- due_changed, resolved, reopened, edited. Do not store secrets or full message bodies here.
-- Rows are append-only, updated_at exists only for consistency.

CREATE TABLE IF NOT EXISTS ticket_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  ticket_id      BIGINT UNSIGNED NOT NULL,
  actor_user_id  BIGINT UNSIGNED NULL COMMENT 'NULL for requester or system actions',
  event_type     VARCHAR(80)     NOT NULL,
  old_value      JSON            NULL,
  new_value      JSON            NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ticket_events_ticket (tenant_id, ticket_id, created_at),
  KEY idx_ticket_events_type (tenant_id, event_type, created_at),
  KEY idx_ticket_events_actor (actor_user_id),
  CONSTRAINT fk_ticket_events_ticket FOREIGN KEY (tenant_id, ticket_id) REFERENCES tickets (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_events_actor  FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
