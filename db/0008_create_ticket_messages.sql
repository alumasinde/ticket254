-- 0008_create_ticket_messages.sql
-- The conversation on a ticket. visibility = 'public' is shown to the requester,
-- 'internal' is staff only and must never appear in public or tracking responses.
-- A NULL author_user_id means a public requester or a system message, author_name records who.

CREATE TABLE IF NOT EXISTS ticket_messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  ticket_id       BIGINT UNSIGNED NOT NULL,
  author_user_id  BIGINT UNSIGNED NULL,
  author_name     VARCHAR(160)    NULL,
  visibility      VARCHAR(10)     NOT NULL DEFAULT 'public' COMMENT 'public or internal',
  source          VARCHAR(12)     NOT NULL DEFAULT 'staff' COMMENT 'staff, requester or system',
  body            TEXT            NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_messages_tenant_id (tenant_id, id),
  KEY idx_ticket_messages_ticket (tenant_id, ticket_id, created_at),
  KEY idx_ticket_messages_visibility (tenant_id, ticket_id, visibility),
  KEY idx_ticket_messages_author (author_user_id),
  CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (tenant_id, ticket_id) REFERENCES tickets (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_messages_author FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
