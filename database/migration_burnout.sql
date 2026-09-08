-- ============================================================
-- Additive migration for Engine C — Burnout & Wellness Risk
-- (ml/burnout_model.py). Safe to run against an existing database:
-- it only ADDS a new table, it does not touch users, mood_logs,
-- forecast_cache, or any other existing table/column.
-- ============================================================

CREATE TABLE IF NOT EXISTS burnout_predictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    risk_score DECIMAL(4,3) NOT NULL,              -- 0.000-1.000 probability
    risk_label ENUM('low','medium','high') NOT NULL,
    payload JSON NOT NULL,                          -- full explanation: risk_factors,
                                                     -- protective_factors, data_completeness_pct, features_used
    model_used VARCHAR(50) DEFAULT NULL,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_burnout (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
