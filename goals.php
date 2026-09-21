<?php
// goals.php has moved. Creating and managing habits now lives on the
// Habits page itself, under the six-section structure — there is no
// longer a separate screen for it. This file only keeps the old URL
// working so existing bookmarks and links don't 404.
require_once __DIR__ . '/includes/auth.php';
require_login();
header('Location: habits.php');
exit;
