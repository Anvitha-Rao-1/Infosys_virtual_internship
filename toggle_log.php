<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['error' => 'Not logged in']); exit; }

$goal_id = (int)($_POST['goal_id'] ?? 0);
$date    = $_POST['date'] ?? '';
$uid     = $_SESSION['user_id'];

if (!$goal_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400); echo json_encode(['error' => 'Invalid input']); exit;
}
if ($date > date('Y-m-d')) {
    http_response_code(400); echo json_encode(['error' => 'Cannot log a future date']); exit;
}

// Ownership check
$stmt = $pdo->prepare("SELECT id FROM goals WHERE id=? AND user_id=?");
$stmt->execute([$goal_id, $uid]);
if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['error' => 'Not your goal']); exit; }

// Toggle: if a 'done' log exists for that day, remove it; otherwise insert it
$stmt = $pdo->prepare("SELECT id FROM goal_logs WHERE goal_id=? AND log_date=?");
$stmt->execute([$goal_id, $date]);
$existing = $stmt->fetch();

if ($existing) {
    $pdo->prepare("DELETE FROM goal_logs WHERE id=?")->execute([$existing['id']]);
    $done = false;
} else {
    $pdo->prepare("INSERT INTO goal_logs (goal_id, log_date, status) VALUES (?,?,'done')")->execute([$goal_id, $date]);
    $done = true;
}

require_once __DIR__ . '/includes/helpers.php';
$newly_earned = $done ? evaluate_achievements($pdo, $uid) : [];

echo json_encode([
    'done' => $done,
    'week_percent' => week_percent($pdo, $goal_id),
    'streak' => current_streak($pdo, $goal_id),
    'newly_earned' => $newly_earned,
]);
