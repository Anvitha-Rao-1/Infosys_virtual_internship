<?php
/**
 * includes/shell.php
 * ------------------------------------------------------------------
 * The page shell for redesigned pages: <head>, the six-section nav, and
 * the opening of the canvas. Closed by includes/shell_end.php.
 *
 * This is deliberately SEPARATE from includes/header.php rather than a
 * replacement for it. Pages that have been redesigned use this shell and
 * load only css/sprout.css; pages not yet migrated keep using
 * header.php and css/style.css and are completely untouched. That is
 * what makes the redesign safe to roll out one page at a time — there is
 * never a half-styled screen.
 *
 * Expects: $user (array), $nav (string key of the active section),
 *          $page_title (string).
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/helpers.php';

/**
 * The six sections. Everything the app can do lives under one of these;
 * the things a person touches rarely (settings, reminders, the calendar,
 * the coach, the methodology write-up) sit in the account menu instead of
 * competing for attention in the main nav.
 */
function sprout_sections(): array {
    return [
        'overview'     => ['Overview',     'dashboard.php',   'M3 11.5 12 4l9 7.5M5.5 10v9.5h13V10'],
        'habits'       => ['Habits',       'habits.php',      'M4 12.5 9 17.5 20 6.5'],
        'finance'      => ['Finance',      'finance.php',     'M12 3v18M16.5 7H10a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6H7'],
        'productivity' => ['Productivity', 'focus.php',       'M12 7v5.2l3.4 2M21 12a9 9 0 1 1-9-9 9 9 0 0 1 9 9Z'],
        'forecast'     => ['Forecast',     'simulate.php',    'M3 17.5 9 11l4 3.6L21 6.5M21 6.5h-5m5 0v5'],
        'growth'       => ['Growth',       'gamification.php','M12 21V9.5M12 9.5C12 6 9.5 3.5 5.5 3.5c0 4 2.5 6 6.5 6Zm0 3.5c0-3 2-5 5.5-5 0 3.5-2 5-5.5 5Z'],
    ];
}

$nav = $nav ?? 'overview';
$sections = sprout_sections();
$avatar = $user['avatar_color'] ?? '#6FBF73';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#1B3727">
<title><?= isset($page_title) ? htmlspecialchars($page_title) . ' · Sprout' : 'Sprout' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght,SOFT,WONK@0,9..144,300..700,0..100,0..1&family=Schibsted+Grotesk:wght@400..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/sprout.css">
</head>
<body>
<div class="app">

    <aside class="side">
        <a class="mark" href="dashboard.php" style="text-decoration:none;">
            <svg class="mark-ico" viewBox="0 0 22 26" aria-hidden="true">
                <path class="stem" d="M11 25 V11"/>
                <path class="leaf" d="M11 14 q6-5 10-1 q-5 5-10 1z"/>
            </svg>
            <span class="mark-name">Sprout</span>
        </a>

        <nav class="nav">
            <?php foreach ($sections as $key => [$label, $href, $path]): ?>
            <a href="<?= $href ?>" class="<?= $nav === $key ? 'on' : '' ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>>
                <svg class="n-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="<?= $path ?>"/>
                </svg>
                <span><?= $label ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="side-foot">
            <button class="me" id="meBtn" aria-expanded="false" aria-controls="meMenu">
                <span class="me-av" style="background:<?= htmlspecialchars($avatar) ?>"><?= initials($user['full_name'] ?? 'U') ?></span>
                <span><?= htmlspecialchars(explode(' ', trim($user['full_name'] ?? 'You'))[0]) ?></span>
                <svg class="me-chev" width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
                    <path d="m6 9 6 6 6-6"/>
                </svg>
            </button>
            <div class="me-menu" id="meMenu">
                <a href="coach.php">Coach</a>
                <a href="calendar.php">Calendar</a>
                <a href="reminders.php">Reminders</a>
                <a href="methodology.php">How it works</a>
                <a href="settings.php">Settings</a>
                <a href="logout.php">Log out</a>
            </div>
        </div>
    </aside>

    <main class="canvas">
