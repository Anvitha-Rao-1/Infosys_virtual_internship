<?php
// analyse.php has been split up. Its three tabs now live where the data
// they describe is entered: the Finance tab is part of Finance, the
// Productivity tab is part of Productivity, and the headline picture is
// the Overview. This file only keeps the old URL working.
//
// The old page linked to itself with #productivity / #habits / #finance,
// so those are honoured here rather than dumping everyone on one page.
require_once __DIR__ . '/includes/auth.php';
require_login();

$targets = [
    'finance'      => 'finance.php',
    'productivity' => 'focus.php',
    'habits'       => 'habits.php',
];
$tab = $_GET['tab'] ?? '';
header('Location: ' . ($targets[$tab] ?? 'dashboard.php'));
exit;
