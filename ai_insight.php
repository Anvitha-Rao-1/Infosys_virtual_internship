<?php
/**
 * ai_insight.php — JSON endpoint behind the "Generate AI insight" button.
 *
 * The browser sends only a category and (optionally) which grid point the
 * slider is on. It does NOT send any numbers. This page re-reads the
 * simulation from whatif_cache server-side and builds the structured
 * summary itself, so the figures handed to the AI are always the ones the
 * model actually produced and can't be tampered with from the client.
 *
 * Always returns HTTP 200 with a usable `text`, even when Hugging Face is
 * unreachable — see includes/ai_insight.php for the fallback contract. The
 * `source` field tells the UI which writer produced the text so it can be
 * labelled honestly.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/whatif.php';
require_once __DIR__ . '/includes/ai_insight.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$user = current_user();
$uid = (int)$user['id'];

$category = $_POST['category'] ?? '';
$allowed = ['finance', 'habits', 'productivity', 'burnout'];
if (!in_array($category, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown category']);
    exit;
}

$force = !empty($_POST['refresh']);
$override = (isset($_POST['lever']) && $_POST['lever'] !== '') ? (float)$_POST['lever'] : null;

/* ------------------------------------------------------------------
   Burnout is a sub-simulation living inside the habits payload, and it
   stores its scenarios directly rather than as a lever grid, so it gets
   its own small adapter instead of going through whatif_scenarios().
   ------------------------------------------------------------------ */
if ($category === 'burnout') {
    $habits = get_whatif($pdo, $uid, 'habits');
    $b = $habits['burnout_simulation'] ?? null;
    if (!$b || ($b['status'] ?? null) !== 'ok') {
        echo json_encode([
            'ok' => false,
            'error' => 'No burnout simulation available yet. Log a few mood entries, run ml/burnout_model.py, then ml/whatif_engine.py.',
        ]);
        exit;
    }
    $summary = [
        'category' => 'burnout risk',
        'lever' => $b['lever']['label'],
        'lever_unit' => trim($b['lever']['unit']),
        'metric' => $b['metric']['label'],
        'metric_unit' => '%',
        'better_when' => 'lower',
        'current_value' => $b['current_value'],
        'historical_average' => null,
        'model_used' => $b['model']['method'],
        'model_accuracy_pct' => $b['model']['accuracy'],
        // NOT a backtest on this person's history — this classifier's
        // accuracy comes from a held-out split of the public dataset it
        // was trained on. See whatif_ai_summary() for why this matters.
        'accuracy_basis' => 'held_out_split_of_the_training_dataset',
        'periods_of_history' => null,
        'response_is_flat' => (bool)($b['response']['flat'] ?? false),
    ];
    $base = (float)$b['scenarios']['expected']['headline'];
    foreach (['expected', 'improved', 'risk'] as $k) {
        if (!isset($b['scenarios'][$k])) continue;
        $h = (float)$b['scenarios'][$k]['headline'];
        $summary[$k . '_input'] = $b['scenarios'][$k]['value'];
        $summary[$k . '_prediction'] = $h;
        if ($k !== 'expected') {
            $summary[$k . '_difference'] = round($h - $base, 2);
            $summary[$k . '_pct_change'] = abs($base) > 1e-9 ? round(($h - $base) / abs($base) * 100, 1) : null;
        }
    }
} else {
    $payload = get_whatif($pdo, $uid, $category);
    if (!whatif_ready($payload)) {
        echo json_encode([
            'ok' => false,
            'error' => $payload['message']
                ?? 'No simulation available yet for this category. Run ml/whatif_engine.py once, then reload.',
        ]);
        exit;
    }
    $scenarios = whatif_scenarios($payload, $override);
    $summary = whatif_ai_summary($payload, $scenarios, $category);
}

$result = ai_insight($pdo, $uid, $category, $summary, $force);

echo json_encode([
    'ok' => true,
    'text' => $result['text'],
    'source' => $result['source'],          // 'huggingface' | 'fallback'
    'model' => $result['model'],
    'reason' => $result['reason'],          // why the fallback was used, if it was
    'cached' => $result['cached'],
    'numbers_sent' => $summary,             // shown in the "what was sent to the AI" disclosure
]);
