<?php
// Thin wrapper so each category also has its own dedicated page/URL.
$_GET['cat'] = 'fitness';
require __DIR__ . '/category.php';
