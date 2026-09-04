<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Not logged in']); exit; }
$uid = $_SESSION['user_id'];

$goal_id = trim($_POST['goal_id'] ?? '') !== '' ? (int)$_POST['goal_id'] : null;
$planned_minutes = max(1, min(600, (int)($_POST['planned_minutes'] ?? 0)));
$actual_minutes = max(1, min(600, (int)($_POST['actual_minutes'] ?? 0)));
$status = ($_POST['status'] ?? 'completed') === 'interrupted' ? 'interrupted' : 'completed';

if ($planned_minutes < 1 || $actual_minutes < 1) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid duration']); exit;
}

// Ownership check for the linked goal, if any.
if ($goal_id) {
    $stmt = $pdo->prepare("SELECT id FROM goals WHERE id=? AND user_id=?");
    $stmt->execute([$goal_id, $uid]);
    if (!$stmt->fetch()) { $goal_id = null; }
}

// started_at/ended_at are computed server-side from actual_minutes (rather
// than trusting the browser's clock/timezone), so ended_at is always "now".
$stmt = $pdo->prepare(
    "INSERT INTO focus_sessions (user_id, goal_id, planned_minutes, actual_minutes, status, started_at, ended_at)
     VALUES (?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE), NOW())"
);
$stmt->execute([$uid, $goal_id, $planned_minutes, $actual_minutes, $status, $actual_minutes]);

require_once __DIR__ . '/includes/helpers.php';
echo json_encode([
    'success' => true,
    'stats' => focus_session_stats($pdo, $uid, 7),
]);
