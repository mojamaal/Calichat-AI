-- tenants: budgets, rate limit, timezone, offboarding-friendly
ALTER TABLE tenants ADD COLUMN monthly_token_limit BIGINT NOT NULL DEFAULT 5000000, ADD COLUMN llm_rpm INT NOT NULL DEFAULT 120, ADD COLUMN timezone VARCHAR(60) NOT NULL DEFAULT 'Asia/Colombo';

ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN password_changed_at DATETIME NULL;

ALTER TABLE agents ADD COLUMN tool_ids VARCHAR(500) NULL, MODIFY temperature DECIMAL(3,2) NOT NULL DEFAULT 1.00;
UPDATE agents SET temperature=1.00 WHERE temperature=0.40;

ALTER TABLE channels ADD COLUMN last_webhook_at DATETIME NULL, ADD COLUMN last_error VARCHAR(300) NULL, ADD COLUMN verified_at DATETIME NULL, ADD COLUMN verified_name VARCHAR(150) NULL, ADD COLUMN verify_error VARCHAR(300) NULL;

ALTER TABLE tools ADD COLUMN expose_all TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN cache_ttl INT NOT NULL DEFAULT 0;
UPDATE tools SET response_fields='sku,name,price,currency,stock,variants', cache_ttl=60 WHERE name='search_products' AND (response_fields IS NULL OR response_fields='');

-- contacts become channel-scoped, with explicit consent
ALTER TABLE contacts ADD COLUMN channel_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER tenant_id, ADD COLUMN consent_status ENUM('unknown','opted_in','opted_out') NOT NULL DEFAULT 'unknown', ADD COLUMN consent_source VARCHAR(80) NULL, ADD COLUMN consent_at DATETIME NULL;
UPDATE contacts SET consent_status = IF(opt_in=1,'unknown','opted_out');
UPDATE contacts c JOIN conversations v ON v.contact_id=c.id SET c.channel_id = COALESCE(v.channel_id,0);
ALTER TABLE contacts DROP INDEX uq_contact, DROP COLUMN opt_in, ADD UNIQUE KEY uq_contact (tenant_id, channel_type, channel_id, external_id);

ALTER TABLE conversations ADD COLUMN assigned_to INT UNSIGNED NULL, ADD COLUMN summary TEXT NULL, ADD COLUMN summary_upto BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN last_customer_at DATETIME NULL, ADD COLUMN last_human_at DATETIME NULL, ADD KEY idx_created (tenant_id, created_at);
UPDATE conversations SET is_test=1 WHERE channel_type='playground';

ALTER TABLE messages MODIFY sender ENUM('customer','ai','human','system','note') NOT NULL, ADD COLUMN provider_id VARCHAR(190) NULL, ADD COLUMN media_type VARCHAR(20) NULL;

ALTER TABLE knowledge_chunks ADD COLUMN embedding BLOB NULL, ADD COLUMN embed_model VARCHAR(60) NULL;
ALTER TABLE memories ADD COLUMN source ENUM('customer','system') NOT NULL DEFAULT 'customer';
ALTER TABLE campaign_recipients MODIFY status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued', ADD COLUMN worker VARCHAR(40) NULL, ADD COLUMN claimed_at DATETIME NULL;
ALTER TABLE usage_daily ADD COLUMN ai_calls INT NOT NULL DEFAULT 0, ADD COLUMN ai_errors INT NOT NULL DEFAULT 0, ADD COLUMN latency_ms_total BIGINT NOT NULL DEFAULT 0, ADD COLUMN media_in INT NOT NULL DEFAULT 0, ADD COLUMN test_tokens INT NOT NULL DEFAULT 0;
ALTER TABLE tool_logs ADD COLUMN cache_hit TINYINT(1) NOT NULL DEFAULT 0;

-- durable inbound queue (replaces webhook_events)
CREATE TABLE inbound_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  channel_id INT UNSIGNED NOT NULL,
  provider_id VARCHAR(190) NOT NULL,
  sender VARCHAR(100) NOT NULL,
  sender_name VARCHAR(150) NULL,
  phone VARCHAR(40) NULL,
  msg_type VARCHAR(20) NOT NULL DEFAULT 'text',
  text TEXT NULL,
  media_ref VARCHAR(600) NULL,
  mime VARCHAR(80) NULL,
  status ENUM('received','processing','retry','done','failed','skipped') NOT NULL DEFAULT 'received',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL,
  locked_by VARCHAR(64) NULL,
  locked_at DATETIME NULL,
  message_id BIGINT UNSIGNED NULL,
  conversation_id INT UNSIGNED NULL,
  last_error VARCHAR(500) NULL,
  outcome VARCHAR(40) NULL,
  received_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_evt (channel_id, provider_id),
  KEY idx_q (status, next_attempt_at),
  KEY idx_t (tenant_id, received_at),
  KEY idx_sender (channel_id, sender, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS webhook_events;

CREATE TABLE ai_calls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'reply',
  model VARCHAR(80) NOT NULL,
  ok TINYINT(1) NOT NULL,
  http_status SMALLINT NULL,
  latency_ms INT NOT NULL,
  tokens_in INT NOT NULL DEFAULT 0,
  tokens_out INT NOT NULL DEFAULT 0,
  finish_reason VARCHAR(30) NULL,
  error VARCHAR(300) NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_t (tenant_id, created_at),
  KEY idx_c (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rate_limits (
  bucket VARCHAR(80) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE circuits (
  name VARCHAR(80) NOT NULL PRIMARY KEY,
  failures INT NOT NULL DEFAULT 0,
  open_until DATETIME NULL,
  last_error VARCHAR(300) NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  severity ENUM('info','warn','crit') NOT NULL DEFAULT 'warn',
  type VARCHAR(40) NOT NULL,
  message VARCHAR(500) NOT NULL,
  dedupe_key VARCHAR(120) NULL,
  seen TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  KEY idx_t (tenant_id, resolved_at, created_at),
  KEY idx_d (tenant_id, dedupe_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  target VARCHAR(120) NULL,
  detail VARCHAR(1000) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_t (tenant_id, created_at),
  KEY idx_a (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  ok TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_e (email, created_at),
  KEY idx_i (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  purpose ENUM('reset','invite') NOT NULL DEFAULT 'reset',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_u (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tool_cache (
  cache_key CHAR(64) NOT NULL PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_exp (expires_at),
  KEY idx_t (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE canned_replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  title VARCHAR(80) NOT NULL,
  body TEXT NOT NULL,
  KEY idx_t (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE kb_misses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  qhash CHAR(32) NOT NULL,
  query VARCHAR(300) NOT NULL,
  hits INT NOT NULL DEFAULT 1,
  last_seen DATETIME NOT NULL,
  UNIQUE KEY uq_q (tenant_id, qhash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE handoffs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NOT NULL,
  reason VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by VARCHAR(20) NULL,
  KEY idx_t (tenant_id, created_at),
  KEY idx_c (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worker_heartbeats (
  name VARCHAR(40) NOT NULL PRIMARY KEY,
  last_beat DATETIME NOT NULL,
  info VARCHAR(200) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- referential integrity (clean orphans first)
UPDATE conversations SET channel_id=NULL WHERE channel_id IS NOT NULL AND channel_id NOT IN (SELECT id FROM channels);
UPDATE channels SET agent_id=NULL WHERE agent_id IS NOT NULL AND agent_id NOT IN (SELECT id FROM agents);
DELETE FROM users WHERE tenant_id IS NOT NULL AND tenant_id NOT IN (SELECT id FROM tenants);
DELETE FROM agents WHERE tenant_id NOT IN (SELECT id FROM tenants);
DELETE FROM channels WHERE tenant_id NOT IN (SELECT id FROM tenants);
DELETE FROM connectors WHERE tenant_id NOT IN (SELECT id FROM tenants);
DELETE FROM tools WHERE connector_id NOT IN (SELECT id FROM connectors);
DELETE FROM knowledge_docs WHERE tenant_id NOT IN (SELECT id FROM tenants);
DELETE FROM knowledge_chunks WHERE doc_id NOT IN (SELECT id FROM knowledge_docs);
DELETE FROM contacts WHERE tenant_id NOT IN (SELECT id FROM tenants);
DELETE FROM conversations WHERE contact_id NOT IN (SELECT id FROM contacts);
DELETE FROM messages WHERE conversation_id NOT IN (SELECT id FROM conversations);
DELETE FROM memories WHERE contact_id NOT IN (SELECT id FROM contacts);
DELETE FROM tool_logs WHERE tenant_id NOT IN (SELECT id FROM tenants);
DELETE FROM usage_daily WHERE tenant_id NOT IN (SELECT id FROM tenants);
DELETE FROM campaigns WHERE tenant_id NOT IN (SELECT id FROM tenants) OR channel_id NOT IN (SELECT id FROM channels);
DELETE FROM campaign_recipients WHERE campaign_id NOT IN (SELECT id FROM campaigns);

ALTER TABLE users ADD CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE agents ADD CONSTRAINT fk_agents_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE channels ADD CONSTRAINT fk_channels_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, ADD CONSTRAINT fk_channels_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL;
ALTER TABLE connectors ADD CONSTRAINT fk_connectors_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE tools ADD CONSTRAINT fk_tools_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, ADD CONSTRAINT fk_tools_connector FOREIGN KEY (connector_id) REFERENCES connectors(id) ON DELETE CASCADE;
ALTER TABLE knowledge_docs ADD CONSTRAINT fk_kdocs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE knowledge_chunks ADD CONSTRAINT fk_kchunks_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, ADD CONSTRAINT fk_kchunks_doc FOREIGN KEY (doc_id) REFERENCES knowledge_docs(id) ON DELETE CASCADE;
ALTER TABLE contacts ADD CONSTRAINT fk_contacts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE conversations ADD CONSTRAINT fk_conv_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, ADD CONSTRAINT fk_conv_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE, ADD CONSTRAINT fk_conv_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE SET NULL;
ALTER TABLE messages ADD CONSTRAINT fk_msg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, ADD CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE;
ALTER TABLE memories ADD CONSTRAINT fk_mem_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, ADD CONSTRAINT fk_mem_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE;
ALTER TABLE tool_logs ADD CONSTRAINT fk_tlogs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE usage_daily ADD CONSTRAINT fk_usage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE campaigns ADD CONSTRAINT fk_camp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE, ADD CONSTRAINT fk_camp_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE;
ALTER TABLE campaign_recipients ADD CONSTRAINT fk_cr_camp FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE;
ALTER TABLE inbound_events ADD CONSTRAINT fk_ev_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE ai_calls ADD CONSTRAINT fk_ai_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE alerts ADD CONSTRAINT fk_alerts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE canned_replies ADD CONSTRAINT fk_canned_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE kb_misses ADD CONSTRAINT fk_kbm_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE handoffs ADD CONSTRAINT fk_handoffs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE tool_cache ADD CONSTRAINT fk_tcache_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
ALTER TABLE password_resets ADD CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
