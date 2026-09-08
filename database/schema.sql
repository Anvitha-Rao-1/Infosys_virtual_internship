-- ============================================
-- Habit Tracker - Database Schema
-- Import this in phpMyAdmin (XAMPP) before running the app
-- ============================================

CREATE DATABASE IF NOT EXISTS habit_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE habit_tracker;

-- ---------- Users ----------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    avatar_color VARCHAR(7) DEFAULT '#6C63A6',
    birthdate DATE DEFAULT NULL,
    gender VARCHAR(30) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
-- Safe to re-run — no-ops if these already exist (added for Settings > Biodata).
ALTER TABLE users ADD COLUMN IF NOT EXISTS birthdate DATE DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS gender VARCHAR(30) DEFAULT NULL;

-- ---------- Categories (Academic, Study, Habits, Fitness, Work) ----------
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    icon VARCHAR(10) DEFAULT '🎯',
    color VARCHAR(7) DEFAULT '#6C63A6'
);

INSERT INTO categories (slug, name, icon, color) VALUES
('academic', 'Academic', '🎓', '#D7F171'),
('study',    'Study Habits', '📚', '#C7D8FF'),
('habits',   'Personal Habits', '🌱', '#B8B7FF'),
('fitness',  'Health & Fitness', '💪', '#F7B1E3'),
('work',     'Work & Productivity', '🗂️', '#C7D8FF')
ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon), color = VALUES(color);
-- If you already imported an earlier version of this file, just re-import it again —
-- it's safe to run and will only refresh the 5 category colours above, nothing else.

-- ---------- Goals ----------
CREATE TABLE IF NOT EXISTS goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(300) DEFAULT '',
    frequency ENUM('daily','weekly') DEFAULT 'daily',
    target_per_week INT DEFAULT 7,
    est_minutes INT DEFAULT 20,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);
-- Already have this table from before? This adds the one new column safely —
-- it's a no-op if you re-run it and est_minutes already exists.
ALTER TABLE goals ADD COLUMN IF NOT EXISTS est_minutes INT DEFAULT 20;
-- est_minutes = "typical minutes per check-in", set per-goal on the Add Goal
-- form (defaults to 20). It's a self-reported estimate, not a stopwatch
-- measurement — it powers the Time Allocation chart and Productivity Score
-- on the Forecast page (see ml/README_ML.md).

-- ---------- Goal activity log (one row per day a goal is checked in) ----------
CREATE TABLE IF NOT EXISTS goal_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    goal_id INT NOT NULL,
    log_date DATE NOT NULL,
    status ENUM('done','skipped') DEFAULT 'done',
    note VARCHAR(200) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_goal_day (goal_id, log_date),
    FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE
);

-- ---------- Mood tracking: one row per user per day ----------
CREATE TABLE IF NOT EXISTS mood_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    log_date DATE NOT NULL,
    mood ENUM('happy','calm','sleepy','stressed','sad','bored') NOT NULL,
    sleep_hours DECIMAL(3,1) DEFAULT NULL,
    stress_level ENUM('low','medium','high') DEFAULT NULL,
    note VARCHAR(200) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_day (user_id, log_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ---------- Achievements (fixed catalogue, evaluated against real stats) ----------
CREATE TABLE IF NOT EXISTS achievements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    title VARCHAR(100) NOT NULL,
    description VARCHAR(200) NOT NULL,
    icon VARCHAR(10) DEFAULT '🏆',
    xp_reward INT DEFAULT 25
);

INSERT INTO achievements (code, title, description, icon, xp_reward) VALUES
('first_checkin',   'First Step',        'Complete your very first check-in',        '🌱', 10),
('streak_7',        '7-Day Streak',      'Reach a 7-day streak on any goal',         '🔥', 25),
('streak_30',       '30-Day Streak',     'Reach a 30-day streak on any goal',        '⚡', 75),
('checkins_25',     'Getting Consistent','Log 25 total check-ins',                   '✅', 25),
('checkins_100',    'Habit Machine',     'Log 100 total check-ins',                  '💪', 60),
('five_goals',      'Goal Setter',       'Create 5 or more active goals',            '🎯', 20),
('all_categories',  'Well Rounded',      'Add a goal in every category',             '🌈', 30),
('perfect_week',    'Perfect Week',      'Complete every active goal, every day, for one week', '👑', 50),
('mood_streak_7',   'Checked In',        'Log your mood 7 days in a row',            '💛', 25)
ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), icon = VALUES(icon), xp_reward = VALUES(xp_reward);

-- ---------- Achievements a user has actually earned ----------
CREATE TABLE IF NOT EXISTS user_achievements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    achievement_id INT NOT NULL,
    earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_achievement (user_id, achievement_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
);

-- ---------- Finance: income & expense transactions ----------
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('income','expense') NOT NULL,
    category VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    txn_date DATE NOT NULL,
    note VARCHAR(200) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ---------- Focus sessions (Focus Sessions tab: real timer + log) ----------
CREATE TABLE IF NOT EXISTS focus_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    goal_id INT DEFAULT NULL,
    planned_minutes INT NOT NULL,
    actual_minutes INT NOT NULL,
    status ENUM('completed','interrupted') DEFAULT 'completed',
    started_at DATETIME NOT NULL,
    ended_at DATETIME DEFAULT NULL,
    note VARCHAR(200) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE SET NULL
);

-- ---------- Reminders: in-app only (no email/push sending is wired up) ----------
CREATE TABLE IF NOT EXISTS reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    goal_id INT DEFAULT NULL,
    title VARCHAR(150) NOT NULL,
    remind_time TIME NOT NULL,
    days_of_week VARCHAR(40) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE SET NULL
);

-- ---------- Financial goals (savings, emergency fund, debt payoff, etc.) ----------
CREATE TABLE IF NOT EXISTS financial_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    goal_type ENUM('savings','emergency_fund','debt_payoff','investment',
                    'purchase','education','travel','income_target') DEFAULT 'savings',
    title VARCHAR(150) NOT NULL,
    target_amount DECIMAL(12,2) NOT NULL,
    starting_amount DECIMAL(12,2) DEFAULT 0,
    start_date DATE NOT NULL,
    target_date DATE NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ---------- Contributions toward a financial goal ----------
-- current_amount is never stored on financial_goals directly — it's always
-- starting_amount + SUM(goal_contributions.amount), same "derived live,
-- never drifts" philosophy as XP in includes/helpers.php.
CREATE TABLE IF NOT EXISTS goal_contributions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    goal_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    contributed_at DATE NOT NULL,
    note VARCHAR(200) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (goal_id) REFERENCES financial_goals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ---------- Forecast cache: latest ML output per user, per domain ----------
-- Written by ml/train_model.py, read (never written) by forecast.php.
-- One row per (user, forecast_type) — retraining overwrites it in place.
CREATE TABLE IF NOT EXISTS forecast_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    forecast_type ENUM('finance','habit') NOT NULL,
    payload JSON NOT NULL,
    model_used VARCHAR(50) DEFAULT NULL,
    mae DECIMAL(10,2) DEFAULT NULL,
    rmse DECIMAL(10,2) DEFAULT NULL,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_type (user_id, forecast_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
