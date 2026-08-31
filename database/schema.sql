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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

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
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

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
