<?php
/**
 * includes/narrative.php
 * ------------------------------------------------------------------
 * Turns the numbers the app already has into sentences a person
 * actually wants to read.
 *
 * ====================================================================
 * This file computes NO new predictions. Every figure it uses comes from
 * an existing helper or an existing cache table. Its entire job is
 * presentation: deciding what is worth saying, and saying it in plain
 * English.
 *
 * Two rules it exists to enforce:
 *
 *   1. NO MACHINE-LEARNING VOCABULARY. The person reading this is not a
 *      developer. Never "our regression model predicts", "the algorithm
 *      detected", "backtested MAPE". Say "if your last few weeks are
 *      anything to go by" and "you're on track for". The maths is
 *      unchanged; only the voice is.
 *
 *   2. EVERY NUMBER ARRIVES WITH A REASON TO CARE. A figure on its own is
 *      a fact. A figure plus what it means for next week is an insight.
 *      Each builder here returns a claim, the evidence, and a takeaway,
 *      so a chart can never be rendered bare.
 *
 * Anything genuinely technical (which model, how accurate) still lives
 * on the "How it works" page for people who go looking. It is never
 * pushed at someone reading their dashboard.
 * ====================================================================
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/whatif.php';

/* ============================================================
   Formatting — human scale, not accountant scale
   ============================================================ */

/** 4283 -> "₹4.3K".  People read ₹4.3K faster than ₹4,283. */
function nar_money($n): string {
    $n = (float)$n;
    $sign = $n < 0 ? '-' : '';
    $a = abs($n);
    if ($a >= 10000000) return $sign . '₹' . rtrim(rtrim(number_format($a / 10000000, 1), '0'), '.') . 'Cr';
    if ($a >= 100000)   return $sign . '₹' . rtrim(rtrim(number_format($a / 100000, 1), '0'), '.') . 'L';
    if ($a >= 1000)     return $sign . '₹' . rtrim(rtrim(number_format($a / 1000, 1), '0'), '.') . 'K';
    return $sign . '₹' . number_format($a);
}

/** "3 days" / "1 day" — small thing, but "1 days" undoes a lot of polish. */
function nar_plural(int $n, string $one, ?string $many = null): string {
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

/**
 * A simulation's predicted value, in this page's voice.
 *
 * whatif_format_metric() writes "₹20,456", which is right on the
 * analysis pages. Here everything else is written at human scale
 * ("₹25.9K"), and a page that switches between the two mid-sentence
 * looks unconsidered. Non-money metrics are left exactly as the
 * simulator formats them.
 */
function nar_metric_text(array $metric, $value): string {
    if (($metric['prefix'] ?? '') === '₹') return nar_money($value);
    return whatif_format_metric($metric, $value);
}

/**
 * Draws one of the existing charts, then rewrites its legend into plain
 * English.
 *
 * svg_line_chart() in helpers.php ends every chart with
 * "solid = actual · dashed = forecast · shaded = 95% confidence". That
 * wording is correct and stays exactly as it is on the analysis pages,
 * where someone has gone looking for detail. Here it is the last piece
 * of specialist vocabulary on an otherwise plain-English page, so it is
 * swapped out.
 *
 * This rewrites the returned markup rather than changing the chart
 * function, so no existing page is affected.
 */
function nar_chart(array $labels, array $series, int $w = 640, int $h = 210): string {
    $svg = svg_line_chart($labels, $series, $w, $h);
    return preg_replace(
        '/(<span style="font-size:11\.5px;[^"]*margin-left:auto;">).*?(<\/span>)/s',
        '$1<span style="color:var(--ink-soft);">Solid is what happened. Dashed is what\'s likely — the shaded band is how sure we are.</span>$2',
        $svg
    );
}

/* ============================================================
   01 — Where you are
   ============================================================ */

function nar_today($pdo, int $uid): array {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $total = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
                           WHERE g.user_id=? AND gl.log_date=? AND gl.status='done'");
    $stmt->execute([$uid, date('Y-m-d')]);
    $done = (int)$stmt->fetch()['c'];

    $pending = goals_pending_today($pdo, $uid);
    $at_risk = streaks_at_risk($pdo, $uid, $pending);
    $pct = $total > 0 ? (int)round($done / $total * 100) : 0;

    // The headline changes shape with the situation rather than being one
    // sentence with numbers swapped in — that is what stops it reading
    // like a mail merge.
    if ($total === 0) {
        $line = "Let's plant the first one.";
        $sub = "Add a habit you want to keep and Sprout starts tracking from today.";
    } elseif ($done === $total) {
        $line = "Everything's done today.";
        $sub = $at_risk ? "Streaks intact." : "That's the whole list — the rest of today is yours.";
    } elseif ($done === 0) {
        $line = "Nothing ticked off yet today.";
        $sub = "There " . (count($pending) === 1 ? 'is one thing' : 'are ' . count($pending) . ' things') . " waiting. Starting with the smallest one usually works.";
    } else {
        $line = "You're " . $done . " of " . $total . " through today.";
        $sub = nar_plural(count($pending), 'habit') . " left to go.";
    }

    // Streak length per goal, so a row can say what is actually on the
    // line rather than repeating the same warning down the whole list.
    $streak_by_goal = [];
    foreach ($at_risk as $r) $streak_by_goal[$r['goal']['id']] = $r['streak'];

    return [
        'total' => $total, 'done' => $done, 'pct' => $pct,
        'pending' => $pending, 'at_risk' => $at_risk,
        'headline' => $line, 'sub' => $sub,
        'at_risk_ids' => array_keys($streak_by_goal),
        'streak_by_goal' => $streak_by_goal,
    ];
}

/* ============================================================
   02 — What's changing
   ============================================================ */

/**
 * The insight cards. Each is a discovery, not a statistic.
 * Returns at most $limit, strongest first, so the section never turns
 * into a wall of equally-weighted observations.
 */
function nar_changes($pdo, int $uid, int $limit = 4): array {
    $out = [];

    // --- check-in momentum: last 2 weeks vs the 2 before ---
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
        WHERE g.user_id=? AND gl.status='done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)");
    $stmt->execute([$uid]); $recent = (int)$stmt->fetch()['c'];
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
        WHERE g.user_id=? AND gl.status='done'
        AND gl.log_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 27 DAY) AND DATE_SUB(CURDATE(), INTERVAL 14 DAY)");
    $stmt->execute([$uid]); $prev = (int)$stmt->fetch()['c'];

    if ($prev > 0) {
        $change = (int)round(($recent - $prev) / $prev * 100);
        if ($change >= 8) {
            $out[] = ['w' => 100 + $change, 'tone' => 'good', 'kicker' => "You're improving",
                'headline' => "Your consistency is up {$change}% this fortnight.",
                'body' => "You've checked in $recent times over the last two weeks, against $prev in the two before. That's the kind of change that compounds."];
        } elseif ($change <= -12) {
            $out[] = ['w' => 88, 'tone' => 'watch', 'kicker' => 'Worth a look',
                'headline' => "You've eased off about " . abs($change) . "% this fortnight.",
                'body' => "$recent check-ins over the last two weeks, down from $prev. Dips are normal — picking one habit to protect is usually enough to turn it around."];
        }
    }

    // --- a streak on the line right now ---
    $at_risk = streaks_at_risk($pdo, $uid);
    if ($at_risk) {
        $t = $at_risk[0];
        $out[] = ['w' => 130, 'tone' => 'watch', 'kicker' => 'On the line',
            'headline' => "Your " . $t['streak'] . "-day " . $t['goal']['title'] . " streak isn't ticked yet.",
            'body' => "It's the longest one you've got running. A single check-in keeps it alive."];
    }

    // --- spending shape ---
    $fin = get_forecast($pdo, $uid, 'finance');
    if ($fin && ($fin['status'] ?? '') === 'ok') {
        $h = $fin['history']; $f = $fin['forecast'];
        $last_exp = end($h['expense']);
        $next_exp = $f['expense'][0] ?? null;
        if ($last_exp > 0 && $next_exp !== null) {
            $d = (int)round(($next_exp - $last_exp) / $last_exp * 100);
            if ($d >= 10) {
                $out[] = ['w' => 105, 'tone' => 'watch', 'kicker' => 'Watch this',
                    'headline' => "Your spending looks set to climb to about " . nar_money($next_exp) . ".",
                    'body' => "That's roughly {$d}% above the " . nar_money($last_exp) . " you spent last month. Worth leaving a little room."];
            } elseif ($d <= -10) {
                $out[] = ['w' => 95, 'tone' => 'good', 'kicker' => 'Heading down',
                    'headline' => "Your spending is easing toward " . nar_money($next_exp) . ".",
                    'body' => "About " . abs($d) . "% below last month's " . nar_money($last_exp) . ". If it holds, that's more going to savings."];
            }
        }
    }

    // --- strongest / weakest area this week ---
    $cats = category_completion_this_week($pdo, $uid);
    if (count($cats) >= 2) {
        $best = $cats[0]; $worst = end($cats);
        if ($best['rate_pct'] - $worst['rate_pct'] >= 25) {
            $out[] = ['w' => 70, 'tone' => 'calm', 'kicker' => 'Uneven week',
                'headline' => $best['name'] . " is carrying your week at " . $best['rate_pct'] . "%.",
                'body' => $worst['name'] . " is trailing at " . $worst['rate_pct'] . "%. One small win there would even things out."];
        }
    }

    // --- the smallest change with the biggest payoff ---
    // Taken straight from the simulations already stored. Phrased as a
    // possibility rather than a promise, because that is what it is.
    $best_lever = null;
    foreach (['habits', 'productivity', 'finance'] as $cat) {
        $w = get_whatif($pdo, $uid, $cat);
        if (!whatif_ready($w)) continue;
        $sc = whatif_scenarios($w);
        if (empty($sc['improved']) || $sc['improved']['same_as_expected']) continue;
        $pct = abs((float)($sc['improved']['pct_change'] ?? 0));
        if ($pct < 4) continue;
        if (!$best_lever || $pct > $best_lever['pct']) {
            $best_lever = ['pct' => $pct, 'cat' => $cat, 'sc' => $sc, 'w' => $w];
        }
    }
    if ($best_lever) {
        $sc = $best_lever['sc'];
        $phrase = [
            'habits' => "showing up " . $sc['improved']['lever_text'] . " instead of " . $sc['expected']['lever_text'],
            'productivity' => "putting in " . $sc['improved']['lever_text'] . " rather than " . $sc['expected']['lever_text'],
            'finance' => "setting aside " . $sc['improved']['lever_text'] . " instead of " . $sc['expected']['lever_text'],
        ][$best_lever['cat']];
        $metric = $best_lever['w']['metric'];
        $from = nar_metric_text($metric, $sc['expected']['headline']);
        $to   = nar_metric_text($metric, $sc['improved']['headline']);
        $out[] = ['w' => 82, 'tone' => 'ahead', 'kicker' => 'Small change, big difference',
            'headline' => "A nudge here moves you from $from to $to.",
            'body' => "That's what " . $phrase . " could look like. Have a play with it on Forecast."];
    }

    // --- best day of the week ---
    $wd = weekday_productivity_scores($pdo, $uid, 8);
    if (count($wd) >= 3) {
        $out[] = ['w' => 55, 'tone' => 'calm', 'kicker' => 'Your rhythm',
            'headline' => $wd[0]['dname'] . "s are when you do your best work.",
            'body' => "Over the last eight weeks " . $wd[0]['dname'] . " has been your strongest day. Worth putting the hard things there."];
    }

    usort($out, fn($a, $b) => $b['w'] <=> $a['w']);
    return array_slice($out, 0, $limit);
}

/* ============================================================
   03 — What's likely next
   ============================================================ */

/**
 * The forecast, in plain words. This is the one place the app makes a
 * claim about the future, so it is careful to sound like a projection
 * ("on track for", "looks likely") and never a promise.
 */
function nar_next($pdo, int $uid): array {
    $out = ['habit' => null, 'money' => null];

    $hab = get_forecast($pdo, $uid, 'habit');
    if ($hab && ($hab['status'] ?? '') === 'ok') {
        $hist = $hab['history']['completion_pct'] ?? [];
        $next = $hab['forecast']['completion_pct'][0] ?? null;
        if ($next !== null && $hist) {
            $now = (float)end($hist);
            $d = round($next - $now, 1);
            $dir = $d > 2 ? 'up' : ($d < -2 ? 'down' : 'flat');
            $claim = $dir === 'up'
                ? "You're on track for about " . round($next) . "% next week."
                : ($dir === 'down'
                    ? "Next week looks like easing to about " . round($next) . "%."
                    : "You're set to hold steady around " . round($next) . "% next week.");
            $take = $dir === 'up'
                ? "That's " . abs($d) . " points above this week. Keep the same rhythm and it should hold."
                : ($dir === 'down'
                    ? "About " . abs($d) . " points below this week. Protecting one habit is usually enough to flatten a dip like this."
                    : "Roughly where you are now — your routine has settled.");
            // "Try this" — one concrete, small thing, chosen to match the
            // direction the forecast is going rather than a generic tip.
            $try = $dir === 'down'
                ? "Pick the one habit you'd least like to lose and protect that this week. Letting the others slide for a few days is fine."
                : ($dir === 'up'
                    ? "Whatever you changed recently is working. Worth noticing what it was, so you can do it again after a bad week."
                    : "If you want to move this, adding a single extra day a week is usually enough — you can see what that looks like on Forecast.");
            $out['habit'] = [
                'claim' => $claim,
                'why' => "This is where your last few weeks point, if nothing much changes.",
                'take' => $take,
                'try' => $try,
                'history' => $hist, 'forecast' => $hab['forecast']['completion_pct'],
                'lower' => $hab['forecast']['completion_pct_lower'] ?? [],
                'upper' => $hab['forecast']['completion_pct_upper'] ?? [],
                'next' => $next, 'now' => $now,
            ];
        }
    }

    $fin = get_forecast($pdo, $uid, 'finance');
    if ($fin && ($fin['status'] ?? '') === 'ok') {
        $h = $fin['history']; $f = $fin['forecast'];
        $save = $f['profit'][0] ?? null;
        if ($save !== null) {
            $last_save = end($h['profit']);
            $d = $save - $last_save;
            $claim = $save >= 0
                ? "You're on course to put away about " . nar_money($save) . " next month."
                : "Next month looks like running about " . nar_money(abs($save)) . " short.";
            $take = abs($d) < max(200, abs($last_save) * 0.08)
                ? "Much the same as this month — your money habits have settled into a pattern."
                : ($d > 0
                    ? "That's " . nar_money(abs($d)) . " better than this month."
                    : "That's " . nar_money(abs($d)) . " less than this month, mostly on the spending side.");
            $try = $save < 0
                ? "One recurring expense is usually easier to cut than a dozen small ones. Your biggest category is the place to look first."
                : ($d < 0
                    ? "Spending is what's moving here, not income. Trimming a single recurring cost would show up in this line next month."
                    : "If you want to push this further, Forecast will show you what holding back a little more each month would add up to.");
            $out['money'] = [
                'claim' => $claim,
                'why' => "Based on the rhythm of what you've earned and spent so far.",
                'take' => $take,
                'try' => $try,
                'months' => $h['months'], 'income' => $h['income'], 'expense' => $h['expense'],
                'f_income' => $f['income'], 'f_expense' => $f['expense'],
                'save' => $save,
            ];
        }
    }
    return $out;
}

/* ============================================================
   Connections — "these two things move together"
   ============================================================ */

/**
 * Turns the correlation analysis the app already runs into sentences.
 *
 * The maths is untouched — pearson_r() over the same point sets the
 * Analyse page has always used. What changes is that "r = 0.72" becomes
 * "your sleep and your follow-through move together", and that every
 * single one of these carries the causation caveat, because a
 * correlation genuinely cannot tell you which way the arrow points.
 *
 * Weak relationships are dropped rather than shown with a shrug: below
 * about 0.3 there is nothing worth a person's attention, and printing it
 * anyway would teach them to distrust the strong ones.
 */
function nar_links($pdo, int $uid): array {
    $out = [];

    $pairs = [
        [
            'points' => wellness_completion_correlation($pdo, $uid, 8),
            'up'    => "On the weeks you sleep and feel better, you follow through more.",
            'down'  => "Oddly, your better weeks and your busier weeks haven't lined up.",
            'why'   => "Each dot is one week: how you were feeling against how much you got done.",
            'x' => 'How you felt', 'y' => 'How much you did',
            'try'   => "Protecting sleep on the weeks you know will be heavy is often easier than trying to push harder through them.",
        ],
        [
            'points' => focus_completion_daily($pdo, $uid, 30),
            'up'    => "The days you sit down to focus are the days the rest gets done too.",
            'down'  => "Your focused time and your check-ins haven't moved together this month.",
            'why'   => "Each dot is one day over the last month: minutes focused against habits completed.",
            'x' => 'Minutes focused', 'y' => 'Habits done',
            'try'   => "A single short session early seems to pull the rest of the day along with it. Worth trying on a slow morning.",
        ],
    ];

    // How many pairs we had enough data to actually test. Reported so the
    // page can distinguish "nothing to say yet" from "we looked and found
    // nothing strong" — which are very different messages.
    $GLOBALS['nar_links_checked'] = 0;

    foreach ($pairs as $p) {
        if (count($p['points']) < 5) continue;
        $r = pearson_r($p['points']);
        if ($r === null) continue;
        $GLOBALS['nar_links_checked']++;
        // Below about 0.3 there is nothing a person should act on. Showing
        // it anyway would dress up noise as an insight and teach them to
        // distrust the strong ones when they do appear.
        if (abs($r) < 0.3) continue;

        $strength = abs($r) >= 0.6 ? 'clearly' : 'somewhat';
        $out[] = [
            'claim' => $r > 0 ? $p['up'] : $p['down'],
            'why'   => $p['why'],
            'points' => $p['points'], 'x' => $p['x'], 'y' => $p['y'],
            'r' => round($r, 2),
            // Stated every time, deliberately. It is the single easiest
            // thing for a product like this to quietly imply and get wrong.
            'take'  => "The two move " . $strength . " together. That doesn't prove one causes the other — "
                     . "a good week can just as easily be what produces both — but it's a pattern worth knowing about yourself.",
            'try'   => $p['try'],
        ];
    }
    return $out;
}

/** How many relationships nar_links() had enough data to test. */
function nar_links_checked(): int {
    return (int)($GLOBALS['nar_links_checked'] ?? 0);
}

/* ============================================================
   05 — What you can do
   ============================================================ */

/**
 * Actions, each with the difference it would actually make. The sizes
 * come from the simulations already stored — this invents nothing, it
 * just drops the vocabulary of "scenarios" and "levers".
 */
function nar_actions($pdo, int $uid, int $limit = 3): array {
    $out = [];

    $at_risk = streaks_at_risk($pdo, $uid);
    if ($at_risk) {
        $t = $at_risk[0];
        // Attributive, so it reads "a 20-day run" rather than "a 20 days run".
        $out[] = ['w' => 100, 'do' => "Tick off " . $t['goal']['title'] . " today",
            'why' => "Keeps a " . $t['streak'] . "-day run going.", 'href' => 'habits.php'];
    }

    $labels = [
        'habits' => ['Show up one more day a week', 'habits.php'],
        'productivity' => ['Add half an hour of focused time', 'productivity.php'],
        'finance' => ['Put aside a little more each month', 'finance.php'],
    ];
    foreach (['habits', 'productivity', 'finance'] as $cat) {
        $w = get_whatif($pdo, $uid, $cat);
        if (!whatif_ready($w)) continue;
        $sc = whatif_scenarios($w);
        if (empty($sc['improved']) || $sc['improved']['same_as_expected']) continue;
        $diff = $sc['improved']['difference'];
        if ($diff === null || abs($diff) < 0.5) continue;

        $from = nar_metric_text($w['metric'], $sc['expected']['headline']);
        $to   = nar_metric_text($w['metric'], $sc['improved']['headline']);
        $out[] = ['w' => 60 + min(30, abs((float)$sc['improved']['pct_change'])),
            'do' => $labels[$cat][0],
            'why' => "Could take you from $from to $to.",
            'href' => $labels[$cat][1]];
    }

    usort($out, fn($a, $b) => $b['w'] <=> $a['w']);
    return array_slice($out, 0, $limit);
}

/* ============================================================
   06 — See your growth
   ============================================================ */

/**
 * The Sprout's own state. Six stages, each pinned to a real, countable
 * milestone so the plant can never grow for reasons the person can't
 * point at.
 */
function nar_growth($pdo, int $uid): array {
    $checkins = total_checkins($pdo, $uid);
    $best_ever = all_time_best_streak($pdo, $uid);
    $level = user_level_info($pdo, $uid);
    $current = overall_best_streak($pdo, $uid);

    $stages = [
        ['at' => 0,   'name' => 'Seed',          'line' => "Everything starts here."],
        ['at' => 1,   'name' => 'Sprouting',     'line' => "First shoots. The hardest part is behind you."],
        ['at' => 25,  'name' => 'Seedling',      'line' => "Taking root. The routine is starting to hold."],
        ['at' => 100, 'name' => 'Young plant',   'line' => "Properly established now."],
        ['at' => 250, 'name' => 'In full leaf',  'line' => "This is a habit, not an experiment."],
        ['at' => 500, 'name' => 'Flourishing',   'line' => "Hundreds of small decisions, stacked up."],
    ];
    $idx = 0;
    foreach ($stages as $i => $s) if ($checkins >= $s['at']) $idx = $i;
    $next = $stages[$idx + 1] ?? null;

    $to_next = $next ? $next['at'] - $checkins : 0;
    $span = $next ? $next['at'] - $stages[$idx]['at'] : 1;
    $pct = $next ? (int)round((($checkins - $stages[$idx]['at']) / max(1, $span)) * 100) : 100;

    return [
        'checkins' => $checkins, 'best_ever' => $best_ever,
        'level' => $level['level'], 'xp' => $level['xp'],
        'current_streak' => $current['days'] ?? 0,
        'current_streak_title' => $current['title'] ?? null,
        'stage' => $idx, 'stage_name' => $stages[$idx]['name'], 'stage_line' => $stages[$idx]['line'],
        'next_name' => $next['name'] ?? null, 'to_next' => $to_next, 'pct_to_next' => $pct,
    ];
}

/* ============================================================
   04 — Why it matters
   ============================================================ */

/**
 * The stakes, in the person's own numbers. Deliberately projects a year
 * out: a weekly figure is easy to shrug off, the same figure over a year
 * usually isn't.
 */
function nar_stakes($pdo, int $uid): array {
    $out = [];

    $fin = get_forecast($pdo, $uid, 'finance');
    if ($fin && ($fin['status'] ?? '') === 'ok') {
        $save = $fin['forecast']['profit'][0] ?? null;
        if ($save !== null && $save > 0) {
            $out[] = ['n' => nar_money($save * 12), 'l' => "if you kept up next month's saving for a year"];
        }
    }

    $g = nar_growth($pdo, $uid);
    if ($g['current_streak'] > 0) {
        $out[] = ['n' => nar_plural($g['current_streak'], 'day'), 'l' => "your longest run right now, on " . $g['current_streak_title']];
    }
    if ($g['checkins'] > 0) {
        $out[] = ['n' => number_format($g['checkins']), 'l' => "separate times you've chosen to show up"];
    }
    return array_slice($out, 0, 3);
}

/* ============================================================
   The Sprout, drawn from real numbers
   ============================================================ */

/**
 * Renders the plant. Stem height, leaf count and the bud are all
 * functions of the growth stage, so it is a picture of the data rather
 * than an illustration sitting next to it.
 */
function nar_sprout_svg(int $stage, int $w = 200, int $h = 240): string {
    $stage = max(0, min(5, $stage));
    $ground = $h - 34;
    $top = $ground - (34 + $stage * ((($h - 90) - 34) / 5));
    $len = $ground - $top;

    $s = '<svg class="sprout" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true">';
    $cx = $w / 2;

    // soil line
    $s .= '<line class="soil" x1="' . ($cx - 62) . '" y1="' . $ground . '" x2="' . ($cx + 62) . '" y2="' . $ground . '"/>';
    $s .= '<line class="soil" x1="' . ($cx - 40) . '" y1="' . ($ground + 11) . '" x2="' . ($cx + 22) . '" y2="' . ($ground + 11) . '" opacity=".5"/>';

    if ($stage === 0) {
        // A seed still under the soil — the plant hasn't broken ground.
        $s .= '<ellipse class="bud" cx="' . $cx . '" cy="' . ($ground + 6) . '" rx="8" ry="10" style="animation-delay:.2s"/>';
        return $s . '</svg>';
    }

    // Stem: a gentle curve, not a straight line.
    $bend = 13;
    $s .= '<path class="stem" style="--len:' . round($len * 1.25) . '" d="M ' . $cx . ' ' . $ground
        . ' C ' . ($cx - $bend) . ' ' . ($ground - $len * .38)
        . ', ' . ($cx + $bend) . ' ' . ($ground - $len * .68)
        . ', ' . $cx . ' ' . $top . '"/>';

    // Leaves alternate sides, spaced up the stem.
    $leaves = min($stage, 5);
    for ($i = 0; $i < $leaves; $i++) {
        $t = ($i + 1) / ($leaves + 0.6);
        $y = $ground - $len * $t;
        $right = $i % 2 === 0;
        $size = 15 + (4 - min(4, $i)) * 2.2;
        $delay = 1.0 + $i * 0.13;
        $dir = $right ? 1 : -1;
        $x = $cx + ($dir * 2);
        $s .= '<path class="lf' . ($right ? ' r' : '') . '" style="animation-delay:' . $delay . 's"'
            . ' d="M ' . $x . ' ' . $y
            . ' q ' . ($dir * $size) . ' ' . (-$size * .78) . ' ' . ($dir * $size * 1.62) . ' ' . (-$size * .1)
            . ' q ' . (-$dir * $size * .72) . ' ' . ($size * .82) . ' ' . (-$dir * $size * 1.62) . ' ' . ($size * .1) . ' z"/>';
    }

    // A bud only at the final stage — it has to be earned.
    if ($stage >= 5) {
        $s .= '<circle class="bud" cx="' . $cx . '" cy="' . ($top - 5) . '" r="8.5" style="animation-delay:1.8s"/>';
    }
    return $s . '</svg>';
}
