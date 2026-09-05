<?php
// Expects $active (string) to highlight the current nav item
// and $user (array) already loaded by the calling page.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';
$level_info = isset($user['id']) ? user_level_info($pdo, $user['id']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? htmlspecialchars($page_title) . ' — Sprout' : 'Sprout' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">🌱</span>
            <span class="brand-name">Sprout</span>
        </div>
        <nav class="side-nav">
            <a href="dashboard.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">
                <span class="nav-ico"><?= icon('home') ?></span> Dashboard
            </a>
            <div class="nav-label">Track</div>
            <a href="habits.php" class="<?= $active === 'habits' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('check-square') ?></span> Habits</a>
            <a href="mood.php" class="<?= $active === 'mood' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('smile') ?></span> Mood Tracker</a>
            <a href="focus.php" class="<?= $active === 'focus' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('clock') ?></span> Focus Sessions</a>
            <a href="goals.php" class="<?= $active === 'goals' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('target') ?></span> Goals</a>
            <div class="nav-label">Analyze</div>
            <a href="insights.php" class="<?= $active === 'insights' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('bar-chart') ?></span> Insights</a>
            <a href="finance.php" class="<?= $active === 'finance' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('dollar-sign') ?></span> Finance</a>
            <div class="nav-label">Plan</div>
            <a href="calendar.php" class="<?= $active === 'calendar' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('calendar') ?></span> Calendar View</a>
            <a href="reminders.php" class="<?= $active === 'reminders' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('bell') ?></span> Reminders</a>
            <div class="nav-label">More</div>
            <a href="gamification.php" class="<?= $active === 'gamification' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('award') ?></span> Rewards</a>
            <a href="coach.php" class="<?= $active === 'coach' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('sparkle') ?></span> AI Coach</a>
            <div class="nav-label">Account</div>
            <a href="settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>"><span class="nav-ico"><?= icon('settings') ?></span> Settings</a>
            <a href="logout.php"><span class="nav-ico"><?= icon('log-out') ?></span> Log out</a>
        </nav>
    </aside>
    <main class="main-panel">
        <header class="topbar">
            <div class="topbar-title"><?= isset($page_title) ? htmlspecialchars($page_title) : '' ?></div>
            <div class="topbar-user">
                <?php if (isset($level_info)): ?>
                <a href="gamification.php" class="xp-pill">⚡ Lv.<?= $level_info['level'] ?></a>
                <?php endif; ?>
                <span class="avatar" style="background:<?= htmlspecialchars($user['avatar_color'] ?? '#6C63A6') ?>"><?= initials($user['full_name'] ?? 'U') ?></span>
                <span class="topbar-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></span>
            </div>
        </header>
        <div class="content">
