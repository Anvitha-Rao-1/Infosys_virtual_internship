<?php
// insights.php has moved — everything it used to show (Overview, Trends,
// Correlations, Forecast) now lives split across analyse.php's
// Productivity and Habits tabs. This file only preserves the old URL/
// bookmark so it doesn't 404.
require_once __DIR__ . '/includes/auth.php';
require_login();
header('Location: analyse.php#productivity');
exit;
