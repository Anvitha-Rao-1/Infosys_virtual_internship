<?php
// ============================================
// Database connection (PDO + MySQL / XAMPP)
// Edit these 4 values only if your XAMPP setup differs
// ============================================
$DB_HOST = 'localhost';
$DB_NAME = 'habit_tracker';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
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
