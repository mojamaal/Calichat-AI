-- ===== Phase 1: built-in store + lightweight CRM, WhatsApp template manager, follow-up automations =====
SET NAMES utf8mb4;

CREATE TABLE store_products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  sku VARCHAR(30) NOT NULL,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(80) NULL,
  description TEXT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'LKR',
  stock INT NOT NULL DEFAULT 0,
  variants VARCHAR(200) NULL,
  colors VARCHAR(200) NULL,
  image_url VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sku (tenant_id, sku),
  KEY idx_cat (tenant_id, category),
  CONSTRAINT fk_sp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_zones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  town VARCHAR(80) NOT NULL,
  fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  free_over DECIMAL(12,2) NULL,
  eta VARCHAR(60) NULL,
  cod_allowed TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_town (tenant_id, town),
  CONSTRAINT fk_sz_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  order_no VARCHAR(20) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  customer_name VARCHAR(100) NULL,
  status ENUM('processing','packed','shipped','delivered','cancelled','returned') NOT NULL DEFAULT 'processing',
  payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash on delivery',
  payment_status ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  delivery_city VARCHAR(60) NULL,
  delivery_address VARCHAR(200) NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  courier VARCHAR(60) NULL,
  tracking VARCHAR(60) NULL,
  eta VARCHAR(80) NULL,
  notes VARCHAR(300) NULL,
  source ENUM('ai','manual') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  shipped_at DATETIME NULL,
  delivered_at DATETIME NULL,
  UNIQUE KEY uq_order (tenant_id, order_no),
  KEY idx_phone (tenant_id, phone),
  KEY idx_status (tenant_id, status, created_at),
  CONSTRAINT fk_so_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  sku VARCHAR(30) NOT NULL,
  name VARCHAR(150) NOT NULL,
  size VARCHAR(20) NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  KEY idx_order (order_id),
  CONSTRAINT fk_soi_order FOREIGN KEY (order_id) REFERENCES store_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_idempotency (
  tenant_id INT UNSIGNED NOT NULL,
  idem_key CHAR(64) NOT NULL,
  response MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, idem_key),
  CONSTRAINT fk_si_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_tokens (
  tenant_id INT UNSIGNED NOT NULL PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_st_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE crm_leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  interest VARCHAR(200) NOT NULL,
  preferred_time VARCHAR(80) NULL,
  notes VARCHAR(500) NULL,
  source ENUM('ai','manual') NOT NULL DEFAULT 'manual',
  stage ENUM('new','contacted','qualified','won','lost') NOT NULL DEFAULT 'new',
  value DECIMAL(12,2) NULL,
  assigned_to INT UNSIGNED NULL,
  lost_reason VARCHAR(200) NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stage (tenant_id, stage, created_at),
  KEY idx_phone (tenant_id, phone),
  CONSTRAINT fk_cl_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE crm_lead_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  lead_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  body VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lead (lead_id),
  CONSTRAINT fk_cln_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wa_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  channel_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  language VARCHAR(10) NOT NULL DEFAULT 'en',
  category ENUM('utility','marketing') NOT NULL DEFAULT 'utility',
  header_text VARCHAR(60) NULL,
  body TEXT NOT NULL,
  footer VARCHAR(60) NULL,
  buttons TEXT NULL,
  status ENUM('draft','pending','approved','rejected','paused','disabled') NOT NULL DEFAULT 'draft',
  meta_id VARCHAR(40) NULL,
  rejection_reason VARCHAR(300) NULL,
  submitted_at DATETIME NULL,
  synced_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tpl (tenant_id, name, language),
  CONSTRAINT fk_wt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE automations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  trigger_type VARCHAR(30) NOT NULL,
  delay_minutes INT NOT NULL DEFAULT 0,
  channel_id INT UNSIGNED NULL,
  body TEXT NULL,
  template_id INT UNSIGNED NULL,
  conditions TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_trig (tenant_id, trigger_type, is_active),
  CONSTRAINT fk_au_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE automation_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  automation_id INT UNSIGNED NOT NULL,
  subject_type VARCHAR(12) NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  phone VARCHAR(20) NULL,
  due_at DATETIME NOT NULL,
  status ENUM('scheduled','sent','skipped','failed') NOT NULL DEFAULT 'scheduled',
  reason VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  done_at DATETIME NULL,
  UNIQUE KEY uq_run (automation_id, subject_type, subject_id),
  KEY idx_due (status, due_at),
  KEY idx_t (tenant_id, created_at),
  CONSTRAINT fk_ar_auto FOREIGN KEY (automation_id) REFERENCES automations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ar_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
