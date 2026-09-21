<?php
// Best-effort "Re-simulate" button on simulate.php. Runs ml/whatif_engine.py
// via shell_exec if PHP is allowed to do that on this XAMPP install; if not
// (shell_exec disabled, python not on PATH, etc.) it fails gracefully and
// tells the user to run it manually — the app never depends on this working,
// exactly like run_forecast.php, which this file deliberately mirrors.
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['success' => false, 'reason' => 'Not logged in']); exit; }

$disabled = array_map('trim', explode(',', ini_get('disable_functions')));
if (!function_exists('shell_exec') || in_array('shell_exec', $disabled)) {
    echo json_encode(['success' => false, 'reason' => 'shell_exec is disabled on this PHP install']);
    exit;
}

$ml_dir = __DIR__ . '/ml';
// Only this user's grids are rebuilt — re-simulating is a per-user action,
// and rebuilding every account's grids would make the button slow for no
// reason on a multi-user install.
$uid = (int)$_SESSION['user_id'];
$cmd = 'cd /d ' . escapeshellarg($ml_dir) . ' && python whatif_engine.py --user ' . $uid . ' 2>&1';
$output = @shell_exec($cmd);

if ($output === null) {
    echo json_encode(['success' => false, 'reason' => 'could not start python (is it installed and on PATH?)']);
    exit;
}

$ok = stripos($output, 'Done.') !== false;

// On Windows the Python console encodes non-ASCII output (em dashes, the ₹
// sign) as cp1252, which is NOT valid UTF-8. json_encode() returns false on
// invalid UTF-8, which would send a completely empty 200 response and make
// this look like a silent failure. Convert before encoding.
if (!mb_check_encoding($output, 'UTF-8')) {
    $output = mb_convert_encoding($output, 'UTF-8', 'Windows-1252');
}

echo json_encode([
    'success' => $ok,
    'reason' => $ok ? null : 'script ran but did not finish cleanly',
    'output' => $output,
]);
