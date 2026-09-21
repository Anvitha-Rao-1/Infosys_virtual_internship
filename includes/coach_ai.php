<?php
/**
 * includes/coach_ai.php
 * ------------------------------------------------------------------
 * The AI Coach chat — a question box over the user's own data.
 *
 * ====================================================================
 * THE ONE RULE, same as the rest of this project:
 *
 *   The language model may only repeat numbers it was handed.
 *   It may never produce one of its own.
 *
 * A general chatbot asked "how much did I spend last month?" will happily
 * invent a plausible figure. That is the single worst thing this feature
 * could do, so it is prevented structurally rather than hoped away:
 *
 *   1. Before any question is sent, coach_facts() computes a sheet of REAL
 *      numbers from the database, using the very same helper functions the
 *      rest of the app displays. Nothing here is recomputed by hand, so the
 *      coach can never disagree with the dashboard.
 *   2. That sheet is rendered as plain "label: value" lines and placed in
 *      the system prompt.
 *   3. The prompt forbids inventing, estimating or calculating anything,
 *      and instructs the model to say plainly when a fact is not on the
 *      sheet.
 *
 * If the model is asked something the sheet does not cover, the correct
 * behaviour is to say so — not to guess.
 * ====================================================================
 *
 * FAILURE BEHAVIOUR. The chat still works with no API key, a rejected key,
 * a rate limit or no internet: coach_fallback_reply() answers from the same
 * facts sheet using keyword matching, and the reply is labelled as locally
 * written. The user always gets their real numbers back; only the wording
 * is less fluent.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_insight.php';   // for hf_chat() and env/config
require_once __DIR__ . '/whatif.php';       // for get_whatif()

const COACH_HISTORY_TURNS = 8;   // how much conversation the model is shown
const COACH_MAX_QUESTION  = 500; // characters accepted from the user

/* ============================================================
   1. The facts sheet — every number the coach is allowed to use
   ============================================================ */

/**
 * Builds the user's real, current numbers.
 *
 * Every value comes from an existing helper or an existing cache table, so
 * the coach and the rest of the app can never quote different figures for
 * the same thing. Anything unavailable is simply left out rather than
 * defaulted to zero — a missing fact and a zero are very different claims.
 */
function coach_facts($pdo, int $uid, array $user): array {
    $f = ['name' => explode(' ', trim($user['full_name']))[0]];

    /* ---- habits ---- */
    $f['habit_score'] = habit_score($pdo, $uid);
    $f['total_checkins'] = total_checkins($pdo, $uid);
    $best = overall_best_streak($pdo, $uid);
    if (!empty($best['days'])) {
        $f['current_best_streak_days'] = $best['days'];
        $f['current_best_streak_habit'] = $best['title'];
    }
    $f['all_time_best_streak_days'] = all_time_best_streak($pdo, $uid);

    $tasks = tasks_completed_today($pdo, $uid);
    $f['habits_done_today'] = $tasks['done'];
    $f['habits_total_today'] = $tasks['total'];

    $week = weekly_goal_progress($pdo, $uid);
    $f['this_week_completion_pct'] = $week['pct'];

    $pending = goals_pending_today($pdo, $uid);
    $f['habits_pending_today'] = $pending ? implode(', ', array_map(fn($g) => $g['title'], $pending)) : 'none - all done';

    $at_risk = streaks_at_risk($pdo, $uid, $pending);
    if ($at_risk) {
        $f['streaks_at_risk_today'] = implode('; ', array_map(
            fn($r) => $r['goal']['title'] . ' (' . $r['streak'] . ' days)', array_slice($at_risk, 0, 3)));
    }

    $cats = category_completion_this_week($pdo, $uid);
    if ($cats) {
        $f['strongest_category_this_week'] = $cats[0]['name'] . ' (' . $cats[0]['rate_pct'] . '%)';
        $worst = end($cats);
        if ($worst['name'] !== $cats[0]['name']) {
            $f['weakest_category_this_week'] = $worst['name'] . ' (' . $worst['rate_pct'] . '%)';
        }
    }

    $day = best_weekday($pdo, $uid);
    if ($day) $f['most_consistent_weekday'] = $day['dname'];

    $wd = weekday_productivity_scores($pdo, $uid, 8);
    if ($wd) {
        $f['most_productive_weekday'] = $wd[0]['dname'] . ' (' . $wd[0]['score'] . '/100 average)';
        if (count($wd) > 1) {
            $least = end($wd);
            $f['least_productive_weekday'] = $least['dname'] . ' (' . $least['score'] . '/100 average)';
        }
    }

    /* ---- wellness ---- */
    $f['wellness_score'] = wellness_score($pdo, $uid);
    $mood = todays_mood($pdo, $uid);
    $f['mood_logged_today'] = $mood ? $mood['mood'] : 'not logged yet today';
    if ($mood && $mood['sleep_hours'] !== null) $f['sleep_hours_last_night'] = $mood['sleep_hours'];
    if ($mood && $mood['stress_level']) $f['stress_level_today'] = $mood['stress_level'];
    $f['mood_logging_streak_days'] = mood_streak_days($pdo, $uid);

    $stmt = $pdo->prepare("SELECT ROUND(AVG(sleep_hours),1) avg_sleep FROM mood_logs
                           WHERE user_id=? AND sleep_hours IS NOT NULL AND log_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)");
    $stmt->execute([$uid]);
    $avg_sleep = $stmt->fetch()['avg_sleep'];
    if ($avg_sleep !== null) $f['average_sleep_last_14_days'] = $avg_sleep . ' hours';

    /* ---- focus ---- */
    $focus = focus_session_stats($pdo, $uid, 7);
    $f['focus_sessions_last_7_days'] = $focus['sessions'];
    $f['focus_minutes_last_7_days'] = $focus['total_minutes'];
    if ($focus['sessions'] > 0) $f['focus_session_completion_rate'] = $focus['success_rate'] . '%';

    /* ---- money ---- */
    $mt = monthly_transaction_totals($pdo, $uid, 6);
    if (!empty($mt['months'])) {
        $i = count($mt['months']) - 1;
        $inc = (float)$mt['income'][$i];
        $exp = (float)$mt['expense'][$i];
        $f['current_month'] = $mt['months'][$i];
        $f['income_this_month'] = 'Rs ' . number_format($inc);
        $f['expenses_this_month'] = 'Rs ' . number_format($exp);
        $f['saved_this_month'] = 'Rs ' . number_format($inc - $exp);
        if ($inc > 0) $f['savings_rate_this_month'] = round(($inc - $exp) / $inc * 100, 1) . '%';
    }

    $health = financial_health_score($pdo, $uid);
    if ($health) $f['financial_health_score'] = $health['score'] . '/100';

    $stmt = $pdo->prepare("SELECT category, SUM(amount) t FROM transactions
                           WHERE user_id=? AND type='expense' AND txn_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                           GROUP BY category ORDER BY t DESC LIMIT 3");
    $stmt->execute([$uid]);
    $top = $stmt->fetchAll();
    if ($top) {
        $f['biggest_spending_categories_last_30_days'] = implode(', ', array_map(
            fn($r) => $r['category'] . ' (Rs ' . number_format((float)$r['t']) . ')', $top));
    }

    foreach (financial_goals_with_forecast($pdo, $uid) as $g) {
        $f['savings_goal_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($g['title']))] =
            'Rs ' . number_format($g['current_amount']) . ' of Rs ' . number_format($g['target_amount'])
            . ' by ' . date('d M Y', strtotime($g['target_date']));
    }

    /* ---- what the trained models predict ---- */
    $fin = get_forecast($pdo, $uid, 'finance');
    if ($fin && ($fin['status'] ?? '') === 'ok') {
        $f['FORECAST_income_next_month'] = 'Rs ' . number_format($fin['forecast']['income'][0] ?? 0);
        $f['FORECAST_expenses_next_month'] = 'Rs ' . number_format($fin['forecast']['expense'][0] ?? 0);
        $f['FORECAST_savings_next_month'] = 'Rs ' . number_format($fin['forecast']['profit'][0] ?? 0);
        $f['FORECAST_finance_model_used'] = forecast_method_label($fin['model']['expense_method']);
        if ($fin['model']['expense_accuracy'] !== null) {
            $f['FORECAST_finance_model_accuracy'] = $fin['model']['expense_accuracy'] . '% (backtested on your own history)';
        }
    }
    $hab = get_forecast($pdo, $uid, 'habit');
    if ($hab && ($hab['status'] ?? '') === 'ok') {
        $f['FORECAST_completion_next_week'] = round($hab['forecast']['completion_pct'][0] ?? 0, 1) . '%';
        $f['FORECAST_productivity_score_next_week'] = round($hab['forecast']['productivity_score'][0] ?? 0, 1) . '/100';
        $f['FORECAST_habit_model_used'] = forecast_method_label($hab['model']['productivity_method']);
    }

    $stmt = $pdo->prepare("SELECT risk_label, ROUND(risk_score*100,1) pct, payload FROM burnout_predictions WHERE user_id=?");
    $stmt->execute([$uid]);
    if ($b = $stmt->fetch()) {
        $f['BURNOUT_risk'] = $b['pct'] . '% (' . $b['risk_label'] . ')';
        // The classifier already works out WHICH factors pushed the score up
        // or down and stores them. Handing those over means the coach can
        // name real drivers instead of filling the gap with plausible-
        // sounding guesses like "a busy schedule" — which is exactly what it
        // did before these two facts existed.
        $bp = json_decode($b['payload'], true);
        // Each factor is written as a READY-MADE natural sentence, with its
        // direction already in the words.
        //
        // Two bugs drove this shape. Given two bare numbers the model
        // compared them backwards, reading a protective factor (sleep above
        // the typical level) as a risk one. Given the direction as a
        // capitalised marker it got the direction right but quoted the raw
        // marker verbatim into its reply. An 8B model won't reliably
        // paraphrase on instruction, so rather than keep tuning the prompt,
        // the fact itself is phrased so that quoting it verbatim still reads
        // correctly. Shaping the input beats fighting the model.
        foreach ([['risk_factors', 'BURNOUT_factors_raising_risk', 'which is pushing your risk up'],
                  ['protective_factors', 'BURNOUT_factors_lowering_risk', 'which is helping keep your risk down']] as [$src, $key, $dir]) {
            if (!empty($bp[$src]) && is_array($bp[$src])) {
                $f[$key] = implode('; ', array_map(
                    fn($r) => 'your ' . strtolower($r['label']) . ' is running at ' . round((float)$r['value'], 1)
                            . ' against a typical ' . $r['benchmark_avg'] . ', ' . $dir
                            . (!empty($r['from_benchmark']) ? ' (this one is estimated, since you do not track it)' : ''),
                    array_slice($bp[$src], 0, 3)));
            }
        }
    }

    /* ---- what-if scenarios (so "what should I change?" has real answers) ---- */
    foreach (['finance', 'habits', 'productivity'] as $cat) {
        $w = get_whatif($pdo, $uid, $cat);
        if (!whatif_ready($w)) continue;
        $sc = whatif_scenarios($w);
        if (!isset($sc['expected'], $sc['improved'])) continue;
        $f['WHATIF_' . $cat] =
            $w['lever']['label'] . ' - staying at ' . $sc['expected']['lever_text']
            . ' predicts ' . $sc['expected']['headline_text']
            . '; moving to ' . $sc['improved']['lever_text']
            . ' predicts ' . $sc['improved']['headline_text']
            . ' (' . $sc['improved']['difference_text'] . ')';
    }

    $level = user_level_info($pdo, $uid);
    $f['level'] = $level['level'];
    $f['xp'] = $level['xp'];

    // Drop anything that came back null so the model is never shown a blank.
    return array_filter($f, fn($v) => $v !== null && $v !== '');
}

/** Renders the facts sheet as the plain "label: value" block the model sees. */
function coach_facts_text(array $facts): string {
    $lines = [];
    foreach ($facts as $k => $v) {
        $lines[] = str_replace('_', ' ', $k) . ': ' . $v;
    }
    return implode("\n", $lines);
}

/* ============================================================
   2. The prompt
   ============================================================ */

function coach_system_prompt(array $facts): string {
    $sheet = coach_facts_text($facts);
    return <<<TXT
You are Sprout's AI Coach. You help one person understand their own habit, wellbeing, productivity and money tracking data, and decide what to do next.

Below is THE COMPLETE SET OF FACTS you know about this person. It was computed from their real logged data moments ago.

--- BEGIN FACTS ---
$sheet
--- END FACTS ---

Hard rules:
1. NEVER invent, estimate, calculate or guess a number. Only repeat numbers that appear in the facts above, exactly as written.
2. If the answer is not in the facts, say so plainly and briefly - for example "I don't have that tracked yet". Then say what they could log so it would be available. Do NOT guess.
3. Facts starting with FORECAST are predictions from a trained statistical model, not certainties. Say "the model projects" or "it's projected", never "you will".
4. Facts starting with WHATIF are simulations - what the model predicts IF they changed something. Use these when asked what to change or improve.
5. Only ever say "the model projects" about a specific FORECAST or WHATIF fact written above, quoting its numbers. NEVER attribute a projection to the model that is not literally in the facts. If you are suggesting something the model said nothing about - rest, breaks, sleep routine, talking to someone - offer it as your own general suggestion ("it might help to...") and do not credit the model for it.
6. The BURNOUT risk figure comes from a model trained on other people's data and applied to this person. It is a pattern, not a diagnosis. Never give medical advice; suggest rest, routine or talking to someone instead.
6a. NEVER speculate about WHY something is happening. If asked why burnout risk is high, use only the BURNOUT factors listed above. If those are not listed, say the model doesn't break down the reasons. Do not guess at causes like a busy schedule, workload or personal circumstances - you cannot see any of that.
6b. The BURNOUT factor facts are already written as readable sentences - reuse their wording and their numbers as they are. Never flip a direction: a factor described as pushing risk up must stay that way, and one described as helping must stay helping. The burnout figure describes the person's CURRENT pattern - do not call it a projection or a forecast.
7. Money amounts are in Indian rupees. Write them as Rs 1,234. Never recommend specific investments or financial products.
8. Be warm, brief and direct. 2 to 4 sentences for a simple question. Use short paragraphs, no bullet lists unless asked, no markdown headings.
9. Address them as "you". You may use their first name occasionally.
10. If they ask something off-topic (not about their tracking, habits, wellbeing, productivity or money), gently redirect to what you can help with.
TXT;
}

/* ============================================================
   3. History
   ============================================================ */

function coach_history($pdo, int $uid, int $limit = 50): array {
    try {
        $stmt = $pdo->prepare("SELECT role, content, source, model_id, created_at
                               FROM coach_messages WHERE user_id=? ORDER BY id ASC LIMIT $limit");
        $stmt->execute([$uid]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Table missing (migration not run) must never break the page — the
        // chat simply starts fresh every time.
        return [];
    }
}

function coach_save_message($pdo, int $uid, string $role, string $content, string $source, ?string $model = null): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO coach_messages (user_id, role, content, source, model_id) VALUES (?,?,?,?,?)");
        $stmt->execute([$uid, $role, $content, $source, $model]);
    } catch (PDOException $e) {
        // Persistence is a convenience, not a requirement.
    }
}

function coach_clear_history($pdo, int $uid): void {
    try {
        $pdo->prepare("DELETE FROM coach_messages WHERE user_id=?")->execute([$uid]);
    } catch (PDOException $e) {
    }
}

/* ============================================================
   4. Asking a question
   ============================================================ */

/**
 * Answers one question.
 *
 * @return array{text:string, source:string, model:?string, reason:?string}
 */
function coach_ask($pdo, int $uid, array $user, string $question): array {
    $question = trim(mb_substr($question, 0, COACH_MAX_QUESTION));
    $facts = coach_facts($pdo, $uid, $user);

    if (!hf_configured()) {
        return [
            'text' => coach_fallback_reply($question, $facts),
            'source' => 'fallback', 'model' => null, 'reason' => 'no_api_key',
        ];
    }

    // Recent turns give the model enough context to handle follow-ups like
    // "and what about last month?" without resending the whole history.
    $messages = [['role' => 'system', 'content' => coach_system_prompt($facts)]];
    $history = coach_history($pdo, $uid, 200);
    foreach (array_slice($history, -COACH_HISTORY_TURNS * 2) as $m) {
        $messages[] = ['role' => $m['role'], 'content' => $m['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $question];

    // A touch warmer than the What-If writer (0.3): this is conversation,
    // not a fixed summary, so identical phrasing every time reads robotic.
    $res = hf_chat($messages, 0.5, 400);

    if ($res['ok']) {
        return ['text' => $res['text'], 'source' => 'huggingface', 'model' => $res['model'], 'reason' => null];
    }
    return [
        'text' => coach_fallback_reply($question, $facts),
        'source' => 'fallback', 'model' => null, 'reason' => $res['reason'],
    ];
}

/* ============================================================
   5. The local fallback responder
   ============================================================ */

/**
 * Answers from the same facts sheet without a language model.
 *
 * This is keyword matching, not intelligence, and the UI never claims
 * otherwise. It works by spotting what the question is *about* and reading
 * back the matching real numbers. That is far less fluent than the model,
 * but it is never wrong — and "less fluent" is a much better failure than
 * "unavailable", because the numbers are the part that actually matters.
 */
function coach_fallback_reply(string $question, array $facts): string {
    $q = mb_strtolower($question);
    $has = fn(array $words) => (bool)array_filter($words, fn($w) => str_contains($q, $w));
    $f = fn(string $k) => $facts[$k] ?? null;

    // Joins whole sentences, skipping any whose facts were unavailable, so a
    // user with no money logged doesn't get a paragraph of dangling labels.
    $sentences = fn(array $parts) => ($parts = array_filter($parts)) ? implode(' ', $parts) : null;

    // --- money ---
    if ($has(['money', 'spend', 'spent', 'saving', 'save', 'savings', 'expense', 'income', 'budget', 'finance', 'afford', 'rupee', 'cash'])) {
        $r = $sentences([
            ($f('income_this_month') && $f('expenses_this_month'))
                ? "This month you've brought in {$f('income_this_month')} and spent {$f('expenses_this_month')}, leaving you {$f('saved_this_month')} saved"
                    . ($f('savings_rate_this_month') ? " — a savings rate of {$f('savings_rate_this_month')}." : '.')
                : null,
            $f('biggest_spending_categories_last_30_days')
                ? "Your biggest spending over the last 30 days: {$f('biggest_spending_categories_last_30_days')}." : null,
            $f('FORECAST_savings_next_month')
                ? "For next month the model projects savings of {$f('FORECAST_savings_next_month')}." : null,
        ]);
        if ($r) return $r;
    }

    // --- sleep / mood / burnout ---
    if ($has(['sleep', 'tired', 'mood', 'stress', 'burnout', 'wellbeing', 'wellness', 'rest', 'exhaust'])) {
        $r = $sentences([
            $f('wellness_score') ? "Your wellness score is {$f('wellness_score')}/100." : null,
            $f('mood_logged_today') ? "Today you logged your mood as {$f('mood_logged_today')}"
                . ($f('stress_level_today') ? " with {$f('stress_level_today')} stress." : '.') : null,
            $f('average_sleep_last_14_days')
                ? "You've averaged {$f('average_sleep_last_14_days')} of sleep over the last two weeks." : null,
            $f('BURNOUT_risk')
                ? "Your predicted burnout risk is {$f('BURNOUT_risk')} — that comes from a model trained on other people's data and applied to your pattern, so treat it as a signal, not a diagnosis." : null,
        ]);
        if ($r) return $r;
    }

    // --- streaks ---
    if ($has(['streak', 'consecutive', 'in a row'])) {
        $r = $sentences([
            $f('current_best_streak_days')
                ? "Your longest running streak is {$f('current_best_streak_days')} days"
                    . ($f('current_best_streak_habit') ? " on {$f('current_best_streak_habit')}." : '.')
                : null,
            $f('all_time_best_streak_days')
                ? "Your all-time best is {$f('all_time_best_streak_days')} days." : null,
            $f('streaks_at_risk_today')
                ? "Still unticked today and at risk: {$f('streaks_at_risk_today')}."
                : "Nothing is at risk today.",
        ]);
        if ($r) return $r;
    }

    // --- productivity / focus / study ---
    if ($has(['productiv', 'focus', 'study', 'work', 'concentrat', 'hours'])) {
        $r = $sentences([
            $f('focus_sessions_last_7_days') !== null
                ? "You've logged {$f('focus_sessions_last_7_days')} focus sessions in the last 7 days, totalling {$f('focus_minutes_last_7_days')} minutes." : null,
            $f('most_productive_weekday')
                ? "Your most productive day is {$f('most_productive_weekday')}"
                    . ($f('least_productive_weekday') ? ", and your quietest is {$f('least_productive_weekday')}." : '.')
                : null,
            $f('FORECAST_productivity_score_next_week')
                ? "The model projects a Productivity Score of {$f('FORECAST_productivity_score_next_week')} next week." : null,
        ]);
        if ($r) return $r;
    }

    // --- what should I do / improve ---
    if ($has(['should i', 'what do', 'improve', 'better', 'advice', 'recommend', 'suggest', 'help me', 'focus on'])) {
        $bits = [];
        foreach (['WHATIF_habits', 'WHATIF_productivity', 'WHATIF_finance'] as $k) {
            if (isset($facts[$k])) $bits[] = $facts[$k];
        }
        if (isset($facts['streaks_at_risk_today'])) {
            array_unshift($bits, 'Right now these streaks are unticked and at risk: ' . $facts['streaks_at_risk_today']);
        }
        if (isset($facts['weakest_category_this_week'])) {
            $bits[] = 'Your weakest area this week is ' . $facts['weakest_category_this_week'];
        }
        if ($bits) {
            return "Based on your simulations, here is what would move the needle. " . implode('. ', $bits) . '.';
        }
    }

    // --- today / progress ---
    if ($has(['today', 'progress', 'how am i', 'doing', 'week'])) {
        $r = $sentences([
            $f('habits_done_today') !== null
                ? "You've completed {$f('habits_done_today')} of {$f('habits_total_today')} habits today." : null,
            $f('this_week_completion_pct') !== null
                ? "This week you're at {$f('this_week_completion_pct')}% completion"
                    . ($f('habit_score') !== null ? ", and your habit score is {$f('habit_score')}/100." : '.')
                : null,
            ($f('habits_pending_today') && $f('habits_pending_today') !== 'none - all done')
                ? "Still to do: {$f('habits_pending_today')}."
                : "Nothing left pending today.",
            $f('strongest_category_this_week')
                ? "Strongest category this week: {$f('strongest_category_this_week')}"
                    . ($f('weakest_category_this_week') ? "; weakest: {$f('weakest_category_this_week')}." : '.')
                : null,
        ]);
        if ($r) return $r;
    }

    // --- nothing matched ---
    $topics = [];
    if (isset($facts['income_this_month'])) $topics[] = 'your money';
    if (isset($facts['habit_score'])) $topics[] = 'habits and streaks';
    if (isset($facts['wellness_score'])) $topics[] = 'sleep, mood and burnout risk';
    if (isset($facts['focus_sessions_last_7_days'])) $topics[] = 'focus and productivity';
    $list = $topics ? implode(', ', $topics) : 'your tracking data';

    return "The AI writer isn't reachable right now, so I'm answering from your data directly — "
         . "which means I need a clearer keyword to go on. Try asking about $list. "
         . "For example: \"how are my streaks?\", \"how much did I save this month?\" or \"what should I improve?\"";
}
