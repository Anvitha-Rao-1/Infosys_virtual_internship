<?php
session_start();
require_once __DIR__ . '/db.php';

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function current_user() {
    global $pdo;
    if (!is_logged_in()) return null;
    $stmt = $pdo->prepare("SELECT id, full_name, email, avatar_color, birthdate, gender, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $out = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $out .= strtoupper(substr(end($parts), 0, 1));
    return $out;
}
