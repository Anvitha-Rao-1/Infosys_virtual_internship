<?php
// mood.php has moved. Logging how you're feeling is now the first thing
// on the Habits page, so looking after yourself and keeping your streak
// are answered in one screen instead of two. This file only keeps the
// old URL working.
require_once __DIR__ . '/includes/auth.php';
require_login();
header('Location: habits.php');
exit;
