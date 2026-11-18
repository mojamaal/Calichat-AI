-- ===== Phase 2: order-detail extraction, colours, customer profiles, bank slips, appointments, ad attribution, catalogue sources, SMS fallback =====
SET NAMES utf8mb4;

ALTER TABLE store_order_items ADD COLUMN color VARCHAR(30) NULL AFTER size;
ALTER TABLE store_orders MODIFY payment_status ENUM('unpaid','review','paid','refunded') NOT NULL DEFAULT 'unpaid';
ALTER TABLE tools ADD COLUMN no_confirm TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE customer_profiles (
  tenant_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  name VARCHAR(100) NULL,
  address VARCHAR(200) NULL,
  city VARCHAR(60) NULL,
  payment_method VARCHAR(30) NULL,
  orders_count INT NOT NULL DEFAULT 0,
  last_order_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, phone),
  CONSTRAINT fk_cp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_slips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL,
  conversation_id INT UNSIGNED NULL,
  phone VARCHAR(20) NOT NULL,
  file VARCHAR(80) NULL,
  mime VARCHAR(30) NULL,
  is_slip TINYINT(1) NOT NULL DEFAULT 1,
  amount DECIMAL(12,2) NULL,
  reference VARCHAR(80) NULL,
  bank VARCHAR(80) NULL,
  paid_on VARCHAR(40) NULL,
  amount_matches TINYINT(1) NULL,
  confidence VARCHAR(10) NULL,
  status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  note VARCHAR(250) NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_t (tenant_id, status, created_at),
  KEY idx_order (order_id),
  CONSTRAINT fk_ps_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appt_services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  capacity TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_svc (tenant_id, name),
  CONSTRAINT fk_as_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  customer_name VARCHAR(100) NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  status ENUM('booked','completed','cancelled','no_show') NOT NULL DEFAULT 'booked',
  notes VARCHAR(300) NULL,
  source ENUM('ai','manual') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_time (tenant_id, start_at),
  KEY idx_phone (tenant_id, phone),
  CONSTRAINT fk_ap_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ap_service FOREIGN KEY (service_id) REFERENCES appt_services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE inbound_events ADD COLUMN referral TEXT NULL;
ALTER TABLE contacts ADD COLUMN acquisition_source VARCHAR(30) NULL, ADD COLUMN acquisition_detail VARCHAR(300) NULL;
ALTER TABLE crm_leads MODIFY source ENUM('ai','manual','ad_form','ad_click') NOT NULL DEFAULT 'manual', ADD COLUMN email VARCHAR(150) NULL, ADD COLUMN external_ref VARCHAR(60) NULL, ADD UNIQUE KEY uq_ext (tenant_id, external_ref);

CREATE TABLE store_sources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  type ENUM('woocommerce','shopify') NOT NULL,
  base_url VARCHAR(200) NOT NULL,
  credentials TEXT NULL,
  auto_sync TINYINT(1) NOT NULL DEFAULT 0,
  last_sync_at DATETIME NULL,
  last_status VARCHAR(300) NULL,
  last_count INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_src (tenant_id, type),
  CONSTRAINT fk_ss_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE automations ADD COLUMN sms_fallback TINYINT(1) NOT NULL DEFAULT 0;
