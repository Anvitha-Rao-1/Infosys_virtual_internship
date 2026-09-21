<?php
/**
 * coach_chat.php — JSON endpoint behind the AI Coach chat box.
 *
 * The browser sends a question. This page computes the user's real numbers
 * server-side, asks the model to answer using only those, saves both
 * messages, and replies.
 *
 * The browser never sends any data about the user — only their question.
 * Everything the model is told is gathered here, from the database, so a
 * tampered request cannot feed the coach false figures.
 *
 * Always returns HTTP 200 with a usable answer when the question is valid:
 * if Hugging Face is unreachable the local responder answers from the same
 * facts, and `source` says which happened.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_ai.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$user = current_user();
$uid = (int)$user['id'];

/* ---- clearing the conversation ---- */
if (isset($_POST['clear'])) {
    coach_clear_history($pdo, $uid);
    echo json_encode(['ok' => true, 'cleared' => true]);
    exit;
}

$question = trim($_POST['message'] ?? '');

if ($question === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Type a question first.']);
    exit;
}
if (mb_strlen($question) > COACH_MAX_QUESTION) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'That question is a bit long — keep it under ' . COACH_MAX_QUESTION . ' characters.',
    ]);
    exit;
}

// Saved before the model is called, so the user's message is never lost if
// the request times out halfway through.
coach_save_message($pdo, $uid, 'user', $question, 'user');

$reply = coach_ask($pdo, $uid, $user, $question);

coach_save_message($pdo, $uid, 'assistant', $reply['text'], $reply['source'], $reply['model']);

echo json_encode([
    'ok' => true,
    'text' => $reply['text'],
    'source' => $reply['source'],   // 'huggingface' | 'fallback'
    'model' => $reply['model'],
    'reason' => $reply['reason'],   // why the fallback was used, if it was
]);
