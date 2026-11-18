-- ===== Demo ERP: write operations (orders, leads) =====
ALTER TABLE demo_orders ADD COLUMN delivery_address VARCHAR(200) NULL;
UPDATE demo_orders SET delivery_address = CONCAT('No. 12, Temple Road, ', delivery_city) WHERE delivery_address IS NULL;

CREATE TABLE demo_leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  interest VARCHAR(200) NOT NULL,
  preferred_time VARCHAR(80) NULL,
  notes VARCHAR(300) NULL,
  status ENUM('new','confirmed','cancelled') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL,
  confirmed_at DATETIME NULL,
  KEY idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE demo_idempotency (
  idem_key CHAR(64) NOT NULL PRIMARY KEY,
  response MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO demo_leads (phone, name, interest, preferred_time, notes, status, created_at, confirmed_at) VALUES
('94771234567','Nimal Perera','20 polo shirts for office uniforms','Weekdays after 5pm','Wants a bulk quote','new',NOW() - INTERVAL 2 DAY,NULL),
('94712345678','Dilani Fernando','Wedding saree collection viewing','Saturday morning',NULL,'confirmed',NOW() - INTERVAL 6 DAY,NOW() - INTERVAL 5 DAY),
('94777654321','Ruwan Jayasinghe','Running shoes size 43 restock alert',NULL,NULL,'new',NOW() - INTERVAL 1 DAY,NULL);

-- ===== AI actions: server-enforced confirmation, optional team approval, write audit =====
ALTER TABLE tools ADD COLUMN requires_approval TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE tool_logs ADD COLUMN is_write TINYINT(1) NOT NULL DEFAULT 0, ADD KEY idx_write (conversation_id, is_write, created_at);

CREATE TABLE action_previews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NOT NULL,
  tool_id INT UNSIGNED NOT NULL,
  args_hash CHAR(64) NOT NULL,
  customer_msg_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME NULL,
  KEY idx_lookup (conversation_id, tool_id, args_hash),
  CONSTRAINT fk_prev_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_prev_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pending_actions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  tool_id INT UNSIGNED NOT NULL,
  tool_name VARCHAR(64) NOT NULL,
  args LONGTEXT NOT NULL,
  summary VARCHAR(600) NOT NULL,
  status ENUM('pending','done','failed','rejected') NOT NULL DEFAULT 'pending',
  result LONGTEXT NULL,
  decided_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  KEY idx_t (tenant_id, status, created_at),
  CONSTRAINT fk_pa_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== One-click Meta connect (platform-owned Meta app) =====
CREATE TABLE platform_settings (
  skey VARCHAR(60) NOT NULL PRIMARY KEY,
  svalue TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE channels ADD COLUMN managed TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN waba_id VARCHAR(40) NULL, ADD KEY idx_route (type, external_id);

CREATE TABLE meta_pending (
  id CHAR(32) NOT NULL PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  CONSTRAINT fk_mp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
