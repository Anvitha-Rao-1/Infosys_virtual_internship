<?php
// burnout.php has moved. The burnout signal now appears on the Overview
// when it matters, and can be explored properly on Forecast, where you
// can see what more sleep would actually do. This file only keeps the
// old URL working.
require_once __DIR__ . '/includes/auth.php';
require_login();
header('Location: simulate.php');
exit;
