-- 0010_create_attachments_tokens.sql
-- Private attachments (files live outside public/, storage_key is a random name, never a user path)
-- and public tracking tokens (only the SHA-256 hash of the token is stored).

CREATE TABLE IF NOT EXISTS ticket_attachments (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NOT NULL,
  ticket_id        BIGINT UNSIGNED NOT NULL,
  message_id       BIGINT UNSIGNED NULL,
  storage_key      VARCHAR(64)     NOT NULL COMMENT 'Random name inside storage/private',
  original_name    VARCHAR(255)    NOT NULL,
  mime_type        VARCHAR(127)    NOT NULL,
  size_bytes       BIGINT UNSIGNED NOT NULL,
  checksum_sha256  CHAR(64)        NULL,
  uploaded_by      BIGINT UNSIGNED NULL COMMENT 'NULL when uploaded by a public requester',
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_attachments_storage_key (storage_key),
  KEY idx_ticket_attachments_ticket (tenant_id, ticket_id),
  KEY idx_ticket_attachments_message (tenant_id, message_id),
  KEY idx_ticket_attachments_uploader (uploaded_by),
  CONSTRAINT fk_ticket_attachments_ticket  FOREIGN KEY (tenant_id, ticket_id)  REFERENCES tickets (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_attachments_message FOREIGN KEY (tenant_id, message_id) REFERENCES ticket_messages (tenant_id, id),
  CONSTRAINT fk_ticket_attachments_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  ticket_id     BIGINT UNSIGNED NOT NULL,
  token_hash    CHAR(64)        NOT NULL COMMENT 'SHA-256 of the random token shown once',
  expires_at    DATETIME        NULL,
  revoked_at    DATETIME        NULL,
  last_used_at  DATETIME        NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_tokens_hash (token_hash),
  KEY idx_ticket_tokens_ticket (tenant_id, ticket_id),
  CONSTRAINT fk_ticket_tokens_ticket FOREIGN KEY (tenant_id, ticket_id) REFERENCES tickets (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
