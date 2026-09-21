<?php
// insights.php has moved. What it used to show now lives on Productivity
// (trends, rhythm, what's next) and the Overview (the headline picture).
// This file only keeps the old URL working.
require_once __DIR__ . '/includes/auth.php';
require_login();
header('Location: focus.php');
exit;
