<?php
// ============================================
// Database connection (PDO + MySQL / XAMPP)
// Edit these 4 values only if your XAMPP setup differs
// ============================================
$DB_HOST = 'localhost';
$DB_PORT = '3307';
$DB_NAME = 'habit_tracker';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed. Make sure XAMPP's MySQL is running and the 'habit_tracker' database has been imported. (" . $e->getMessage() . ")");
}

// ------------------------------------------------------------------
// Make PHP agree with MySQL about what time it is.
//
// This app mixes both clocks: toggle_log.php stamps a check-in with
// PHP's date('Y-m-d'), while the helpers that read those rows compare
// against MySQL's CURDATE(). If the two disagree, there is a window
// every night where a check-in is written under one date and looked for
// under another — so it silently fails to show as done.
//
// They did disagree: PHP was on Europe/Berlin (whatever php.ini
// happened to say) while MySQL follows the machine's own clock, leaving
// them 3.5 hours apart. The database follows the machine the user is
// actually sitting at, so PHP is the one brought into line — and it is
// derived from MySQL rather than hardcoded, so this keeps working if the
// project moves to a machine in another country.
// ------------------------------------------------------------------
try {
    $offset = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS s")->fetch()['s'];
    $zone = timezone_name_from_abbr('', (int)$offset, 0);
    if ($zone) date_default_timezone_set($zone);
} catch (Throwable $e) {
    // Never let a clock lookup stop the app loading; PHP just keeps
    // whatever timezone php.ini gave it.
}
