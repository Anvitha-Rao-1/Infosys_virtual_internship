<?php
// Best-effort "Retrain now" button on forecast.php. Runs ml/train_model.py
// via shell_exec if PHP is allowed to do that on this XAMPP install; if not
// (shell_exec disabled, python not on PATH, etc.) it fails gracefully and
// tells the user to run it manually — the app never depends on this working.
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['success' => false, 'reason' => 'Not logged in']); exit; }

$disabled = array_map('trim', explode(',', ini_get('disable_functions')));
if (!function_exists('shell_exec') || in_array('shell_exec', $disabled)) {
    echo json_encode(['success' => false, 'reason' => 'shell_exec is disabled on this PHP install']);
    exit;
}

$ml_dir = __DIR__ . '/ml';
$cmd = 'cd /d ' . escapeshellarg($ml_dir) . ' && python train_model.py 2>&1';
$output = @shell_exec($cmd);

if ($output === null) {
    echo json_encode(['success' => false, 'reason' => 'could not start python (is it installed and on PATH?)']);
    exit;
}

$ok = stripos($output, 'Done.') !== false;

// BUGFIX: train_model.py prints the ₹ sign and em dashes, which the Windows
// Python console emits as cp1252 — not valid UTF-8. json_encode() returns
// false on invalid UTF-8, so this endpoint was returning an empty 200 body
// and the "Retrain now" button always reported "Could not reach the server"
// even when the retrain had actually succeeded. Converting first fixes it.
if (!mb_check_encoding($output, 'UTF-8')) {
    $output = mb_convert_encoding($output, 'UTF-8', 'Windows-1252');
}

echo json_encode(['success' => $ok, 'reason' => $ok ? null : 'script ran but did not finish cleanly', 'output' => $output]);
