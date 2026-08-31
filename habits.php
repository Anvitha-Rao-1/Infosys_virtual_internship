<?php
// Thin wrapper so each category also has its own dedicated page/URL.
$_GET['cat'] = 'habits';
require __DIR__ . '/category.php';
