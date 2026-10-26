-- 0013_create_plans_subscriptions.sql
-- Plans and manual subscriptions. A platform admin sets plan and period by hand at first.
-- Money is stored as integer minor units (KSh 1,000 = 100000 cents). NULL limit means unlimited.

CREATE TABLE IF NOT EXISTS plans (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                    VARCHAR(40)     NOT NULL,
  name                    VARCHAR(80)     NOT NULL,
  monthly_price_minor     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  currency                CHAR(3)         NOT NULL DEFAULT 'KES',
  max_agents              INT UNSIGNED    NULL,
  max_tickets_per_month   INT UNSIGNED    NULL,
  features_json           JSON            NULL,
  is_active               TINYINT(1)      NOT NULL DEFAULT 1,
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id             BIGINT UNSIGNED NOT NULL,
  plan_id               BIGINT UNSIGNED NOT NULL,
  status                VARCHAR(20)     NOT NULL DEFAULT 'trial' COMMENT 'trial, active, past_due, cancelled, suspended',
  current_period_start  DATETIME        NULL,
  current_period_end    DATETIME        NULL,
  cancelled_at          DATETIME        NULL,
  notes                 VARCHAR(255)    NULL COMMENT 'Manual billing notes, for example M-Pesa reference',
  created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_subscriptions_tenant (tenant_id, status),
  KEY idx_subscriptions_plan (plan_id),
  KEY idx_subscriptions_period (status, current_period_end),
  CONSTRAINT fk_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_subscriptions_plan   FOREIGN KEY (plan_id)   REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO plans (id, code, name, monthly_price_minor, max_agents, max_tickets_per_month) VALUES
  (1, 'free',     'Free',     0,      2,    100),
  (2, 'starter',  'Starter',  100000, 3,    500),
  (3, 'team',     'Team',     250000, 10,   2000),
  (4, 'business', 'Business', 600000, 25,   NULL);
