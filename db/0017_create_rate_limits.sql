-- 0017_create_rate_limits.sql
-- Small database-backed rate limiter, no Redis needed.
-- limiter_key examples: login:ip:1.2.3.4, login:email:a@b.co, public_ticket:ip:1.2.3.4, public_ticket:tenant:7
-- The worker deletes rows whose expires_at has passed.

CREATE TABLE IF NOT EXISTS rate_limits (
  limiter_key        VARCHAR(190)  NOT NULL,
  hits               INT UNSIGNED  NOT NULL DEFAULT 0,
  window_started_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at         DATETIME      NOT NULL,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (limiter_key),
  KEY idx_rate_limits_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
