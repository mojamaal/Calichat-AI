SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(80) NOT NULL UNIQUE,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  plan VARCHAR(40) NOT NULL DEFAULT 'starter',
  monthly_message_limit INT NOT NULL DEFAULT 2000,
  settings LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','owner','agent') NOT NULL DEFAULT 'agent',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS agents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  business_name VARCHAR(150) NULL,
  instructions MEDIUMTEXT NULL,
  tone VARCHAR(40) NOT NULL DEFAULT 'friendly',
  languages VARCHAR(120) NOT NULL DEFAULT 'English, Sinhala, Tamil',
  greeting VARCHAR(500) NULL,
  fallback_message VARCHAR(500) NULL,
  temperature DECIMAL(3,2) NOT NULL DEFAULT 0.40,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS channels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NULL,
  type ENUM('whatsapp','messenger','instagram') NOT NULL,
  name VARCHAR(120) NOT NULL,
  external_id VARCHAR(100) NULL,
  access_token TEXT NULL,
  app_secret TEXT NULL,
  verify_token VARCHAR(64) NOT NULL,
  channel_key VARCHAR(40) NOT NULL UNIQUE,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS connectors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  base_url VARCHAR(500) NOT NULL,
  auth_type ENUM('none','bearer','header','basic','query') NOT NULL DEFAULT 'none',
  auth_name VARCHAR(80) NULL,
  auth_secret TEXT NULL,
  timeout_s TINYINT UNSIGNED NOT NULL DEFAULT 12,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tools (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  connector_id INT UNSIGNED NOT NULL,
  name VARCHAR(64) NOT NULL,
  description VARCHAR(1000) NOT NULL,
  method ENUM('GET','POST','PUT','PATCH','DELETE') NOT NULL DEFAULT 'GET',
  path VARCHAR(500) NOT NULL DEFAULT '/',
  params LONGTEXT NULL,
  response_path VARCHAR(200) NULL,
  response_fields VARCHAR(500) NULL,
  allow_write TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tool (tenant_id, name),
  KEY (connector_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_docs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_chunks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  doc_id INT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  KEY (tenant_id), KEY (doc_id),
  FULLTEXT KEY ft_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  channel_type VARCHAR(20) NOT NULL,
  external_id VARCHAR(100) NOT NULL,
  name VARCHAR(150) NULL,
  phone VARCHAR(40) NULL,
  opt_in TINYINT(1) NOT NULL DEFAULT 1,
  tags VARCHAR(255) NULL,
  last_seen DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact (tenant_id, channel_type, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  channel_id INT UNSIGNED NULL,
  channel_type VARCHAR(20) NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NULL,
  status ENUM('ai','human','closed') NOT NULL DEFAULT 'ai',
  handoff_reason VARCHAR(300) NULL,
  unread INT NOT NULL DEFAULT 0,
  last_message_at DATETIME NULL,
  last_preview VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_t (tenant_id, status, last_message_at),
  KEY (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NOT NULL,
  direction ENUM('in','out') NOT NULL,
  sender ENUM('customer','ai','human','system') NOT NULL,
  content TEXT NOT NULL,
  meta LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_c (conversation_id, id),
  KEY idx_t (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel_id INT UNSIGNED NOT NULL,
  external_id VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_evt (channel_id, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS memories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  kind ENUM('fact','persona') NOT NULL DEFAULT 'fact',
  content VARCHAR(600) NOT NULL,
  hash CHAR(32) NOT NULL,
  importance TINYINT NOT NULL DEFAULT 3,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mem (tenant_id, contact_id, hash),
  KEY idx_c (tenant_id, contact_id, kind),
  FULLTEXT KEY ft_mem (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tool_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NULL,
  tool VARCHAR(80) NOT NULL,
  request_summary VARCHAR(1000) NULL,
  http_status SMALLINT NULL,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  latency_ms INT NULL,
  error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usage_daily (
  tenant_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  messages_in INT NOT NULL DEFAULT 0,
  messages_out INT NOT NULL DEFAULT 0,
  ai_replies INT NOT NULL DEFAULT 0,
  handoffs INT NOT NULL DEFAULT 0,
  tool_calls INT NOT NULL DEFAULT 0,
  tokens_in INT NOT NULL DEFAULT 0,
  tokens_out INT NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  channel_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  template_name VARCHAR(120) NULL,
  template_lang VARCHAR(10) NOT NULL DEFAULT 'en',
  body TEXT NOT NULL,
  status ENUM('draft','running','done') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_recipients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  error VARCHAR(300) NULL,
  sent_at DATETIME NULL,
  KEY (campaign_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
