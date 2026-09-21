<?php
/**
 * includes/ai_insight.php
 * ------------------------------------------------------------------
 * The AI INTERPRETATION layer (Hugging Face).
 *
 * ====================================================================
 * WHAT THIS LAYER IS AND IS NOT — the single most important thing to
 * understand about this file:
 *
 *   It produces NO numbers. Not one.
 *
 * Every figure in this project — every forecast, every scenario, every
 * risk score — is produced by the project's own trained models:
 *   ml/forecasting.py + ml/train_model.py   (Linear Regression / ARIMA / XGBoost)
 *   ml/burnout_model.py                      (RandomForestClassifier)
 *   ml/whatif_engine.py                      (those same models, modified inputs)
 *
 * The Hugging Face model is handed those already-computed numbers and asked
 * to do the one thing a language model is actually good at and a regression
 * model cannot do at all: turn a table into a readable paragraph that says
 * what changed and what it means.
 *
 * It is asked to explain, never to calculate. The prompt says so explicitly,
 * and the numbers are interpolated into the prompt as literal values so
 * there is nothing for it to compute.
 * ====================================================================
 *
 * FAILURE BEHAVIOUR. This layer is entirely optional. If the API key is
 * missing, the request fails, the model is unavailable, the network is down
 * or a rate limit is hit, ai_insight() still returns a useful explanation —
 * written locally by ai_fallback_insight() from the same numbers — and
 * reports source='fallback' so the UI can label it honestly. Nothing else in
 * the app depends on this file.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/env.php';

const AI_SYSTEM_PROMPT = <<<'TXT'
You are a careful data analyst writing a short explanation for someone looking at their own personal-analytics dashboard.

You will be given a JSON object of numbers that have ALREADY been computed by a trained machine-learning model. Your job is to explain them in plain language.

Hard rules:
1. NEVER invent, estimate, recompute or round a number. Use only the numbers given to you, exactly as given.
2. If a number is not in the JSON, do not mention it.
3. Do not predict anything yourself. The predictions are already made; you are describing them.
4. Say "the model projects" or "the simulation shows", never "you will".
5. This is a projection from the person's own logged history, not a certainty. Do not promise outcomes.
6. No medical, clinical or financial advice. Suggest behaviour, never treatment or specific investments.
7. The labels IMPROVED and RISK describe the DIRECTION THE LEVER WAS MOVED, not whether the outcome got better or worse. Always read the sign of each difference and describe it accurately. If a scenario labelled RISK produces a BETTER predicted outcome, or one labelled IMPROVED produces a WORSE one, say so plainly — do not describe a decrease as an increase to fit the label.
8. Write 3 to 5 sentences of flowing prose. No bullet points, no headings, no markdown, no preamble like "Here is".
9. Address the reader as "you". Be warm, plain and specific. No hype, no exclamation marks.
TXT;

/* ============================================================
   Public entry point
   ============================================================ */

/**
 * Returns an explanation of an already-computed simulation.
 *
 * @param array $summary The small structured number set from
 *                       whatif_ai_summary(). This is the ONLY data that
 *                       leaves the server — never the raw database.
 * @return array{text:string, source:string, model:?string, reason:?string, cached:bool}
 */
function ai_insight($pdo, int $uid, string $category, array $summary, bool $force_refresh = false): array {
    $hash = sha1(json_encode($summary));

    if (!$force_refresh) {
        $cached = ai_insight_cached($pdo, $uid, $hash);
        if ($cached) return $cached;
    }

    if (!hf_configured()) {
        $result = [
            'text' => ai_fallback_insight($summary),
            'source' => 'fallback',
            'model' => null,
            'reason' => 'no_api_key',
            'cached' => false,
        ];
        ai_insight_store($pdo, $uid, $category, $hash, $summary, $result);
        return $result;
    }

    $remote = ai_call_huggingface($summary);
    if ($remote['ok']) {
        $result = [
            'text' => $remote['text'],
            'source' => 'huggingface',
            'model' => $remote['model'],
            'reason' => null,
            'cached' => false,
        ];
    } else {
        $result = [
            'text' => ai_fallback_insight($summary),
            'source' => 'fallback',
            'model' => null,
            'reason' => $remote['reason'],
            'cached' => false,
        ];
    }
    ai_insight_store($pdo, $uid, $category, $hash, $summary, $result);
    return $result;
}

/* ============================================================
   Cache
   ============================================================ */

function ai_insight_cached($pdo, int $uid, string $hash): ?array {
    try {
        $stmt = $pdo->prepare("SELECT insight_text, source, model_id FROM ai_insight_cache WHERE user_id=? AND scenario_hash=?");
        $stmt->execute([$uid, $hash]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        // The table not existing must never break the page — the insight is
        // simply regenerated every time until migration_whatif.sql is run.
        return null;
    }
    if (!$row) return null;
    return [
        'text' => $row['insight_text'],
        'source' => $row['source'],
        'model' => $row['model_id'],
        'reason' => null,
        'cached' => true,
    ];
}

function ai_insight_store($pdo, int $uid, string $category, string $hash, array $summary, array $result): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO ai_insight_cache (user_id, category, scenario_hash, model_id, source, prompt_payload, insight_text)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE model_id=VALUES(model_id), source=VALUES(source),
                 prompt_payload=VALUES(prompt_payload), insight_text=VALUES(insight_text), created_at=NOW()"
        );
        $stmt->execute([
            $uid, $category, $hash, $result['model'], $result['source'],
            json_encode($summary), $result['text'],
        ]);
    } catch (PDOException $e) {
        // Caching is a nicety, never a requirement. Swallow and move on.
    }
}

/* ============================================================
   The Hugging Face call
   ============================================================ */

/**
 * One non-streaming chat completion against the Hugging Face router
 * (an OpenAI-compatible endpoint).
 *
 * @return array{ok:bool, text:?string, model:?string, reason:?string}
 */
function ai_call_huggingface(array $summary): array {
    return hf_chat([
        ['role' => 'system', 'content' => AI_SYSTEM_PROMPT],
        ['role' => 'user', 'content' => ai_build_user_prompt($summary)],
    ],
    // Low temperature: this is an explanation of fixed numbers, so we want
    // the same scenario to read the same way twice, not creative variation
    // on a factual summary.
    0.3, 320);
}

/**
 * The shared transport: one non-streaming chat completion against the
 * Hugging Face router.
 *
 * Both callers go through here — the What-If insight writer above and the
 * AI Coach chat (includes/coach_ai.php) — so the auth, timeout, curl/stream
 * fallback and error-classification behaviour can only ever be defined once.
 * Anything that needs to change about how this project talks to Hugging Face
 * changes in this one function.
 *
 * @param array $messages OpenAI-style [['role'=>..., 'content'=>...], ...]
 * @return array{ok:bool, text:?string, model:?string, reason:?string}
 */
function hf_chat(array $messages, float $temperature = 0.3, int $max_tokens = 320): array {
    $key     = env_get('HF_API_KEY');
    $model   = env_get('HF_MODEL', 'meta-llama/Llama-3.1-8B-Instruct');
    $url     = env_get('HF_API_URL', 'https://router.huggingface.co/v1/chat/completions');
    $timeout = (int)env_get('HF_TIMEOUT', 20);

    $body = json_encode([
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $max_tokens,
        'stream' => false,
    ], JSON_UNESCAPED_UNICODE);

    if (!function_exists('curl_init')) {
        return ai_call_via_streams($url, $key, $body, $timeout, $model);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'text' => null, 'model' => null, 'reason' => 'network: ' . $err];
    return ai_parse_response($raw, $http, $model);
}

/** Fallback transport for PHP builds without ext/curl. */
function ai_call_via_streams(string $url, ?string $key, string $body, int $timeout, string $model): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer $key\r\nContent-Type: application/json\r\n",
        'content' => $body,
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok' => false, 'text' => null, 'model' => null, 'reason' => 'network: request failed'];
    $http = 200;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $http = (int)$m[1];
    }
    return ai_parse_response($raw, $http, $model);
}

function ai_parse_response(string $raw, int $http, string $model): array {
    $data = json_decode($raw, true);

    if ($http === 401 || $http === 403) {
        return ['ok' => false, 'text' => null, 'model' => null,
                'reason' => 'auth: the Hugging Face token was rejected (check HF_API_KEY has the "Inference Providers" permission)'];
    }
    if ($http === 429) {
        return ['ok' => false, 'text' => null, 'model' => null, 'reason' => 'rate_limit: too many requests, try again shortly'];
    }
    if ($http === 404) {
        return ['ok' => false, 'text' => null, 'model' => null,
                'reason' => "model_unavailable: no provider is serving \"$model\" (change HF_MODEL in .env)"];
    }
    if ($http >= 400) {
        $msg = $data['error']['message'] ?? ($data['error'] ?? 'unknown error');
        if (is_array($msg)) $msg = json_encode($msg);
        return ['ok' => false, 'text' => null, 'model' => null, 'reason' => "http_$http: " . substr((string)$msg, 0, 200)];
    }

    // A non-JSON body with a 2xx status means something in front of the API
    // answered instead of the API — a proxy or CDN interstitial. Saying
    // "empty response" for that sends whoever is debugging in the wrong
    // direction entirely.
    if (!is_array($data)) {
        return ['ok' => false, 'text' => null, 'model' => null,
                'reason' => 'bad_response: the endpoint returned something that is not JSON (a proxy or CDN page?)'];
    }

    $message = $data['choices'][0]['message'] ?? [];
    $text = $message['content'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        // Reasoning models (gpt-oss, GLM-*-Air and friends) split their
        // output into a reasoning channel and a final answer. With a low
        // max_tokens the budget can be spent entirely on reasoning, leaving
        // `content` empty while the call still returns HTTP 200. That is a
        // configuration problem with an obvious fix, so name it rather than
        // reporting a bare "empty".
        if (!empty($message['reasoning']) || !empty($message['reasoning_content'])) {
            return ['ok' => false, 'text' => null, 'model' => null,
                    'reason' => "reasoning_only: \"$model\" spent its whole token budget reasoning and returned no answer. Raise HF_TIMEOUT/max_tokens, or set HF_MODEL to a non-reasoning model such as meta-llama/Llama-3.1-8B-Instruct"];
        }
        return ['ok' => false, 'text' => null, 'model' => null,
                'reason' => "empty: \"$model\" returned no text (some reasoning models do this — try HF_MODEL=meta-llama/Llama-3.1-8B-Instruct)"];
    }

    // Strip any stray markdown the model adds despite being told not to.
    $text = trim(preg_replace('/^\s*(#+\s*|[-*]\s+)/m', '', $text));
    $text = str_replace(['**', '__'], '', $text);

    return ['ok' => true, 'text' => $text, 'model' => $data['model'] ?? $model, 'reason' => null];
}

/**
 * Turns the structured summary into the user turn of the prompt.
 *
 * The numbers are written out as literal text rather than left for the
 * model to pull out of raw JSON — it makes the "do not compute anything"
 * instruction much easier to follow, because the differences and
 * percentage changes it might otherwise be tempted to work out are already
 * sitting there, computed by PHP from the model's own output.
 */
function ai_build_user_prompt(array $s): string {
    $unit = $s['metric_unit'] ?: '';
    $lu = $s['lever_unit'] ?: '';
    $fmt = fn($v) => $v === null ? 'not available' : (is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') : (string)$v);

    $lines = [];
    $lines[] = "Category: {$s['category']}";
    $lines[] = "The lever being simulated: {$s['lever']} (measured in {$lu})";
    $lines[] = "The outcome being predicted: {$s['metric']} (measured in {$unit}); {$s['better_when']} is better.";
    $lines[] = "";
    $lines[] = "What the person is actually doing now: " . $fmt($s['current_value']) . " $lu";
    if ($s['historical_average'] !== null) {
        $lines[] = "Their own historical average: " . $fmt($s['historical_average']) . " $lu";
    }
    $lines[] = "";
    $lines[] = "The three simulated scenarios, all predicted by the trained model:";
    $lines[] = "- EXPECTED (carry on as now): input " . $fmt($s['expected_input'] ?? null) . " $lu -> predicted " . $fmt($s['expected_prediction'] ?? null) . " $unit";

    // The direction of each change is spelled out in words as well as in the
    // sign, because a model asked to narrate a scenario labelled "RISK" will
    // otherwise reach for "increase"/"worse" to match the label even when the
    // number went the other way. Observed doing exactly that on a
    // non-monotonic burnout curve, where the RISK scenario genuinely
    // predicted LOWER risk than the baseline.
    $describe = function (string $label, $input, $pred, $diff, $pct) use ($fmt, $lu, $unit, $s) {
        $txt = "- $label: input " . $fmt($input) . " $lu -> predicted " . $fmt($pred) . " $unit";
        if ($diff === null) return $txt;
        $dir = $diff > 0 ? 'HIGHER' : ($diff < 0 ? 'LOWER' : 'THE SAME AS');
        $txt .= " — that is $dir than EXPECTED, by " . $fmt(abs($diff)) . " $unit";
        if ($pct !== null) $txt .= " (" . $fmt(abs($pct)) . "%)";
        if ($diff != 0) {
            $better = ($s['better_when'] === 'lower') ? ($diff < 0) : ($diff > 0);
            $txt .= $better ? ', which is a BETTER outcome' : ', which is a WORSE outcome';
        }
        return $txt;
    };

    if (isset($s['improved_prediction'])) {
        $lines[] = $describe('IMPROVED', $s['improved_input'], $s['improved_prediction'],
                             $s['improved_difference'] ?? null, $s['improved_pct_change'] ?? null);
    }
    if (isset($s['risk_prediction'])) {
        $lines[] = $describe('RISK', $s['risk_input'], $s['risk_prediction'],
                             $s['risk_difference'] ?? null, $s['risk_pct_change'] ?? null);
    }
    $lines[] = "";
    if ($s['model_used']) {
        $name = ai_model_display_name($s['model_used']);
        $acc = '';
        if (($s['model_accuracy_pct'] ?? null) !== null) {
            $basis = ($s['accuracy_basis'] ?? '') === 'held_out_split_of_the_training_dataset'
                ? 'on a held-out split of the public dataset it was trained on (not on this person\'s own data)'
                : 'when backtested against this person\'s own past periods';
            $acc = ", which scored " . $fmt($s['model_accuracy_pct']) . "% accuracy $basis";
            if ((float)$s['model_accuracy_pct'] < 60) {
                $acc .= ". That accuracy is low, so you must describe this projection as rough and uncertain rather than reliable";
            }
        }
        $lines[] = "The predictions come from a {$name} model{$acc}.";
    }
    if ($s['periods_of_history'] !== null) {
        $lines[] = "They have {$s['periods_of_history']} periods of their own logged history behind this.";
    }
    if (!empty($s['response_is_flat'])) {
        $lines[] = "IMPORTANT: the model's projection barely changes across the whole range of this lever. Say so plainly — at this person's current profile, this lever appears to have little effect on the outcome.";
    }
    $lines[] = "";
    $lines[] = "Explain: where they stand versus their own average, what the simulation says moving this lever would do, and what that suggests they might focus on. Use only the numbers above.";

    return implode("\n", $lines);
}

/* ============================================================
   Local fallback writer — used whenever the API is unavailable
   ============================================================ */

/**
 * Writes the same kind of explanation locally, from the same numbers,
 * with no network call.
 *
 * This is rule-based prose assembly, not a language model, and the UI never
 * claims otherwise — it is labelled "written locally" wherever it appears.
 * It exists so that losing the API costs the user a nicer paragraph and
 * nothing else: the forecast, the scenarios, the comparison and the charts
 * are all still there, and they are still explained.
 */
/**
 * Human-readable name for a model code. The raw codes ("linear_trend",
 * "xgboost") are fine in a database column and wrong in a sentence.
 */
function ai_model_display_name(?string $m): string {
    $map = [
        'linear_trend' => 'Linear Regression',
        'arima' => 'ARIMA',
        'xgboost' => 'XGBoost',
        'moving_average' => 'Moving Average',
        'RandomForestClassifier' => 'Random Forest',
    ];
    if (!$m) return 'forecasting';
    return $map[$m] ?? ucwords(str_replace('_', ' ', $m));
}

function ai_fallback_insight(array $s): string {
    $unit = $s['metric_unit'] ?: '';
    $lu = $s['lever_unit'] ?: '';
    $n = function ($v, $dec = 1) {
        if ($v === null) return '—';
        return rtrim(rtrim(number_format((float)$v, $dec, '.', ','), '0'), '.');
    };
    $with_unit = function ($v) use ($unit, $n) {
        if ($v === null) return '—';
        if ($unit === '₹') return '₹' . number_format((float)$v, 0);
        return $n($v) . $unit;
    };

    // Labels arrive capitalised because they are written for card headings
    // ("Savings rate", "Projected completion rate"). Mid-sentence they need
    // to read as ordinary noun phrases. An all-caps label (an acronym) is
    // left alone.
    $lower_label = function (string $t): string {
        if ($t === '' || mb_strtoupper($t) === $t) return $t;
        return mb_strtolower(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    };
    $metric_name = $lower_label($s['metric']);
    $lever_name = $lower_label($s['lever']);

    $parts = [];

    // 1. Where they stand versus their own history.
    $cur = $s['current_value'];
    $avg = $s['historical_average'];
    if ($cur !== null && $avg !== null && abs($avg) > 1e-9) {
        $delta = $cur - $avg;
        $pct = abs($delta / abs($avg) * 100);
        if ($pct < 5) {
            $parts[] = "Your current {$lever_name} of {$n($cur)}{$lu} is running right about at your own historical average of {$n($avg)}{$lu}.";
        } else {
            $dir = $delta > 0 ? 'above' : 'below';
            $parts[] = "Your current {$lever_name} of {$n($cur)}{$lu} is sitting " . $n($pct) . "% {$dir} your own historical average of {$n($avg)}{$lu}.";
        }
    } elseif ($cur !== null) {
        $parts[] = "Your current {$lever_name} is {$n($cur)}{$lu}.";
    }

    // 2. What the model projects if nothing changes.
    if (isset($s['expected_prediction'])) {
        $model = $s['model_used'] ? " under the " . ai_model_display_name($s['model_used']) . " model" : '';
        $parts[] = "Carrying on at that level, your {$metric_name} comes out at " . $with_unit($s['expected_prediction']) . "{$model}.";
    }

    // 3. The two alternatives, as a single contrast.
    $better = $s['better_when'] === 'lower' ? 'lower' : 'higher';
    if (!empty($s['response_is_flat'])) {
        $parts[] = "Across the whole range of this lever the projection barely moves, so at your current profile the model does not see it as what is driving this outcome — something else is.";
    } else {
        // A percentage change is undefined when the baseline is zero, so it
        // is omitted rather than printed as an em dash inside brackets.
        $describe = function (string $verb, $input, $pred, $pct) use ($n, $lu, $with_unit) {
            $txt = "$verb " . $n($input) . "{$lu} projects " . $with_unit($pred);
            if ($pct !== null) $txt .= " (" . ($pct >= 0 ? '+' : '') . $n($pct) . "%)";
            return $txt;
        };
        $bits = [];
        if (isset($s['improved_prediction'])) {
            $bits[] = $describe('moving to', $s['improved_input'], $s['improved_prediction'], $s['improved_pct_change'] ?? null);
        }
        if (isset($s['risk_prediction'])) {
            $bits[] = $describe('slipping to', $s['risk_input'], $s['risk_prediction'], $s['risk_pct_change'] ?? null);
        }
        if ($bits) {
            $parts[] = "The simulation shows that " . implode(", while ", $bits) . ".";
        }
        // Only claim a direction when the IMPROVED scenario actually came out
        // better. On a non-monotonic curve it may not have, and asserting it
        // anyway would contradict the numbers printed in the sentence above.
        $imp = $s['improved_difference'] ?? null;
        $improved_is_better = $imp === null ? null
            : (($s['better_when'] === 'lower') ? ($imp < 0) : ($imp > 0));
        if ($improved_is_better === true) {
            $parts[] = "So the model associates a {$better} {$metric_name} with moving this lever in that direction — worth keeping in view when you plan the coming weeks.";
        } elseif ($improved_is_better === false) {
            $parts[] = "Note that moving the lever the way you would expect to help did not actually improve the projection here — the model's response to this lever is not a straight line, so the direction that helps depends on where you are starting from.";
        }
    }

    // 4. The honest caveat — including how the accuracy figure was earned,
    // and a sharply different sentence when that accuracy is poor.
    $acc = $s['model_accuracy_pct'] ?? null;
    if ($acc === null) {
        $parts[] = "This is a projection from your own logged history, not a certainty.";
    } elseif (($s['accuracy_basis'] ?? '') === 'held_out_split_of_the_training_dataset') {
        $parts[] = "That model scored " . $n($acc) . "% accuracy on a held-out portion of the public dataset it was trained on — not on your own data — so read it as a pattern learned from a population rather than a measurement of you.";
    } elseif ((float)$acc < 60) {
        $parts[] = "Treat this one loosely: the model only scored " . $n($acc) . "% accuracy when backtested against your own past periods, which usually means your recent history has been too irregular for it to fit well. More consistent logging will tighten it.";
    } else {
        $parts[] = "That projection comes from a model that was " . $n($acc) . "% accurate when backtested against your own past periods, so treat it as a well-grounded projection rather than a certainty.";
    }

    return implode(' ', $parts);
}
