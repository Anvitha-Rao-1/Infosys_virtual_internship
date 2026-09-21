<?php
/**
 * refresh.php — "Update my forecasts".
 *
 * Runs the three Python scripts in the order they depend on each other:
 *
 *     train_model.py   ->  forecasts
 *     burnout_model.py ->  burnout scores
 *     whatif_engine.py ->  scenarios (reads what the other two wrote)
 *
 * Getting that order wrong produces stale or missing scenarios, which is
 * exactly the trap a person clicking a button should not have to know
 * about. That is why this replaced the two separate buttons that used to
 * sit on the old Forecast page: one action, correct sequence, every time.
 *
 * Best-effort by design. If PHP is not allowed to run shell commands on
 * this install, it says so and tells you what to run by hand. Nothing in
 * the app depends on this working — the site simply keeps showing the
 * predictions it already has.
 */

require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'Not logged in']);
    exit;
}

$disabled = array_map('trim', explode(',', ini_get('disable_functions')));
if (!function_exists('shell_exec') || in_array('shell_exec', $disabled)) {
    echo json_encode([
        'ok' => false,
        'reason' => 'This server is not allowed to run background tasks.',
        'manual' => 'python ml/train_model.py && python ml/burnout_model.py && python ml/whatif_engine.py',
    ]);
    exit;
}

$ml_dir = __DIR__ . '/ml';
$uid = (int)$_SESSION['user_id'];

// whatif_engine.py can narrow to one account; the other two always sweep
// every user, which is fine on a single-person install and harmless on a
// shared one.
$steps = [
    'forecasts' => 'python train_model.py',
    'wellbeing' => 'python burnout_model.py',
    'scenarios' => 'python whatif_engine.py --user ' . $uid,
];

$log = [];
$failed = null;

foreach ($steps as $label => $cmd) {
    $out = @shell_exec('cd /d ' . escapeshellarg($ml_dir) . ' && ' . $cmd . ' 2>&1');

    if ($out === null) {
        $failed = 'Could not start Python. Is it installed and on your PATH?';
        break;
    }

    // The Windows Python console emits the rupee sign and em dashes as
    // cp1252, which is not valid UTF-8 — and json_encode() returns false
    // on invalid UTF-8, which would send an empty 200 and look like a
    // silent failure. Convert before encoding. (Same bug that used to
    // break the old Retrain button.)
    if (!mb_check_encoding($out, 'UTF-8')) {
        $out = mb_convert_encoding($out, 'UTF-8', 'Windows-1252');
    }
    $log[$label] = trim($out);

    if (stripos($out, 'Done.') === false) {
        $failed = ucfirst($label) . ' did not finish cleanly.';
        break;
    }
}

echo json_encode([
    'ok' => $failed === null,
    'reason' => $failed,
    'steps' => array_keys($log),
    'log' => $log,
    'manual' => $failed ? 'python ml/train_model.py && python ml/burnout_model.py && python ml/whatif_engine.py' : null,
]);
