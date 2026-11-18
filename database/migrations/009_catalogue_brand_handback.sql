-- Phase 4: branded catalogue + product cards, order confirmation taps, hand-back note when a human returns a chat to the AI.
ALTER TABLE conversations ADD COLUMN handback_note VARCHAR(600) NULL, ADD COLUMN ai_catchup TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE inbound_events ADD COLUMN reply_id VARCHAR(100) NULL;
ALTER TABLE store_orders MODIFY COLUMN source ENUM('ai','manual','catalogue') NOT NULL DEFAULT 'manual';
