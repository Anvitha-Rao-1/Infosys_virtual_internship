<?php
// burnout.php has moved — the burnout-risk gauge and explanation now live
// inside analyse.php's Habits tab, under "Mood & wellness", per the
// Analyse restructure. This file only preserves the old URL/bookmark.
require_once __DIR__ . '/includes/auth.php';
require_login();
header('Location: analyse.php#habits');
exit;
