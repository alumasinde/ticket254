-- 0011_create_notifications.sql
-- Transactional outbox. The row is inserted in the same transaction as the ticket event that needs it.
-- The worker (php hd queue:work) claims pending rows, sends them and records every attempt.
-- tenant_id is NULL for platform emails such as password resets.

CREATE TABLE IF NOT EXISTS notification_outbox (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NULL,
  ticket_id        BIGINT UNSIGNED NULL,
  channel          VARCHAR(20)     NOT NULL DEFAULT 'email' COMMENT 'email now, sms and whatsapp later',
  event_type       VARCHAR(80)     NOT NULL,
  recipient_email  VARCHAR(254)    NOT NULL,
  recipient_name   VARCHAR(160)    NULL,
  payload          JSON            NOT NULL COMMENT 'Template data, no passwords or secrets',
  status           VARCHAR(12)     NOT NULL DEFAULT 'pending' COMMENT 'pending, sending, sent, failed',
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error       VARCHAR(500)    NULL,
  available_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Not sent before this time, used for retry backoff',
  locked_at        DATETIME        NULL,
  sent_at          DATETIME        NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_outbox_queue (status, available_at),
  KEY idx_outbox_tenant (tenant_id, created_at),
  KEY idx_outbox_ticket (tenant_id, ticket_id),
  CONSTRAINT fk_outbox_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  outbox_id     BIGINT UNSIGNED NOT NULL,
  success       TINYINT(1)      NOT NULL DEFAULT 0,
  error         VARCHAR(500)    NULL,
  attempted_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_outbox (outbox_id, attempted_at),
  CONSTRAINT fk_attempts_outbox FOREIGN KEY (outbox_id) REFERENCES notification_outbox (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
