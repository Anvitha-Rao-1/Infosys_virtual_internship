-- ============================================================
-- Migration: AI Coach chat history  (ADDITIVE)
-- ------------------------------------------------------------
-- Run this ONCE in phpMyAdmin (or `mysql < migration_coach_chat.sql`)
-- after database/schema.sql.
--
-- Purely additive: it CREATEs one new table and does not ALTER, DROP or
-- otherwise touch any existing table. Every other feature works exactly
-- the same whether or not you run this — without it the AI Coach page
-- simply starts each visit with an empty conversation.
-- ============================================================

USE habit_tracker;

-- ---------- AI Coach conversation ----------
-- One row per message, user's and coach's alike, so a conversation
-- survives a page reload and the coach can see what was already asked.
--
-- `source` records who actually wrote an assistant message, so the UI can
-- label it honestly and never imply an AI wrote something it didn't:
--   'huggingface' = written by the remote language model
--   'fallback'    = written by the local rule-based responder because the
--                   API was unavailable (no key, rate limit, network, ...)
-- User messages always carry source 'user'.
CREATE TABLE IF NOT EXISTS coach_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content TEXT NOT NULL,
    source ENUM('user','huggingface','fallback') NOT NULL DEFAULT 'user',
    model_id VARCHAR(120) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_time (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
