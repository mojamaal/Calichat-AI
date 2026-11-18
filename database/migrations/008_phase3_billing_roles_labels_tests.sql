-- ===== Phase 3: self sign-up and billing, per-capability staff roles, inbox labels, tenant quality tests =====
SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN permissions TEXT NULL;

CREATE TABLE plans (
  code VARCHAR(30) NOT NULL PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  price_lkr DECIMAL(12,2) NOT NULL DEFAULT 0,
  monthly_message_limit INT NOT NULL DEFAULT 2000,
  monthly_token_limit BIGINT NOT NULL DEFAULT 5000000,
  max_users SMALLINT NOT NULL DEFAULT 5,
  description VARCHAR(200) NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  sort SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO plans (code, name, price_lkr, monthly_message_limit, monthly_token_limit, max_users, description, sort) VALUES
 ('starter', 'Starter', 4900, 1000, 3000000, 3, 'One shop, up to 1,000 AI replies a month', 1),
 ('growth', 'Growth', 12900, 5000, 15000000, 8, 'Busy shops: 5,000 AI replies a month, 8 team members', 2),
 ('pro', 'Pro', 29900, 20000, 60000000, 25, 'High volume: 20,000 AI replies a month, 25 team members', 3);

ALTER TABLE tenants
  ADD COLUMN billing_status ENUM('trial','active','past_due','lapsed','cancelled') NOT NULL DEFAULT 'active',
  ADD COLUMN trial_ends_at DATETIME NULL,
  ADD COLUMN current_period_end DATETIME NULL,
  ADD COLUMN signup_source VARCHAR(20) NULL;

CREATE TABLE invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  number VARCHAR(20) NOT NULL,
  plan_code VARCHAR(30) NOT NULL,
  description VARCHAR(200) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  months TINYINT UNSIGNED NOT NULL DEFAULT 1,
  due_date DATE NOT NULL,
  status ENUM('issued','paid_claimed','paid','void') NOT NULL DEFAULT 'issued',
  payment_ref VARCHAR(100) NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME NULL,
  paid_at DATETIME NULL,
  decided_by INT UNSIGNED NULL,
  UNIQUE KEY uq_number (number),
  KEY idx_t (tenant_id, status),
  CONSTRAINT fk_inv_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE labels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(40) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#5B5BF0',
  UNIQUE KEY uq_label (tenant_id, name),
  CONSTRAINT fk_lb_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE conversation_labels (
  conversation_id INT UNSIGNED NOT NULL,
  label_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (conversation_id, label_id),
  CONSTRAINT fk_cl2_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_cl2_label FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE conversations ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN marked_unread TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE agent_tests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  script TEXT NOT NULL,
  last_status ENUM('never','passed','failed') NOT NULL DEFAULT 'never',
  last_run_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_test (tenant_id, name),
  CONSTRAINT fk_at_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE agent_test_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  test_id INT UNSIGNED NOT NULL,
  passed TINYINT(1) NOT NULL,
  details MEDIUMTEXT NULL,
  model VARCHAR(80) NULL,
  duration_ms INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_test (test_id, id),
  CONSTRAINT fk_atr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_atr_test FOREIGN KEY (test_id) REFERENCES agent_tests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
