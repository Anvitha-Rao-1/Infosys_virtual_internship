<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Analyse';
$active = 'analyse';

// ============================================================
// Analyse — the ONE analysis hub, split into three tabs (Productivity /
// Habits / Finance). This REPLACES the old insights.php + the Goals/
// Forecast tabs of finance.php + burnout.php as separate destinations —
// insights.php and burnout.php now redirect here, and finance.php keeps
// only its "This Month" (transaction entry) tab, with a link into
// Analyse → Finance for goals/forecasts.
//
// IMPORTANT: every number below is still computed by the exact same
// helpers.php functions / ml/*.py-written cache rows as before — this
// file only reorganizes markup. No query, forecast, or ML model changed.
// ============================================================

function money($n) { return '₹' . number_format((float)$n, 0); }
function end_val($arr) { return !empty($arr) ? end($arr) : 0; }
function heat_color($count, $max) {
    if ($count == 0) return '#EFEFEF';
    $ratio = $count / $max;
    if ($ratio > 0.75) return '#8FAE8B';
    if ($ratio > 0.5) return '#B9F0D3';
    if ($ratio > 0.25) return '#DDF5E5';
    return '#EEF9F1';
}
function trend_badge($d) {
    if (!$d) return '';
    $colors = ['up' => 'var(--good-ink)', 'down' => 'var(--attention)', 'flat' => 'var(--ink-soft)'];
    $arrows = ['up' => '▲', 'down' => '▼', 'flat' => '●'];
    return '<div class="caption" style="color:' . $colors[$d['dir']] . '; font-weight:700; margin-top:6px;">' . $arrows[$d['dir']] . ' ' . htmlspecialchars($d['text']) . '</div>';
}

/* ============================================================
   PRODUCTIVITY + HABITS data (ported from the old insights.php —
   same function calls, same order, just no longer split across four
   generic tabs).
   ============================================================ */
$habit = get_forecast($pdo, $uid, 'habit');
$habit_ready = $habit && $habit['status'] === 'ok' && isset($habit['history']['productivity_score']);

$h_score = habit_score($pdo, $uid);
$best_streak = overall_best_streak($pdo, $uid);
$best_streak_ever = all_time_best_streak($pdo, $uid);
$tasks = tasks_completed_today($pdo, $uid);
$goal_progress = weekly_goal_progress($pdo, $uid);
$total_checkins = total_checkins($pdo, $uid);

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
$stmt->execute([$uid]);
$active_goal_count = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT MIN(created_at) mn FROM goals WHERE user_id=?");
$stmt->execute([$uid]);
$first_goal_date = $stmt->fetch()['mn'];
$days_active = $first_goal_date ? max(1, (new DateTime())->diff(new DateTime($first_goal_date))->days + 1) : 1;
$consistency = $active_goal_count > 0 ? min(100, round(($total_checkins / ($active_goal_count * $days_active)) * 100)) : 0;

$cat_perf = category_completion_this_week($pdo, $uid);
$best_cat = $cat_perf[0] ?? null;
$worst_cat = !empty($cat_perf) ? end($cat_perf) : null;
$cat_perf_prev = category_completion_prev_week($pdo, $uid);
$prev_rate_by_id = [];
foreach ($cat_perf_prev as $r) $prev_rate_by_id[$r['id']] = $r['rate_pct'];
$radar_axes = array_map(fn($c) => $c['name'], $cat_perf);
$radar_this_week = array_map(fn($c) => $c['rate_pct'], $cat_perf);
$radar_last_week = array_map(fn($c) => $prev_rate_by_id[$c['id']] ?? 0, $cat_perf);

$top_day = best_weekday($pdo, $uid);
$weekday_scores = weekday_productivity_scores($pdo, $uid, 8);
$top_productive_day = $weekday_scores[0] ?? null;
$least_productive_day = (count($weekday_scores) > 1) ? end($weekday_scores) : null;
$weekday_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$scores_by_day = [];
foreach ($weekday_scores as $w) $scores_by_day[$w['dname']] = $w['score'];
$radar_week_values = array_map(fn($d) => $scores_by_day[$d] ?? 0, $weekday_order);

$prod_hist_all = $habit_ready ? $habit['history']['productivity_score'] : [];
$prod_delta = (count($prod_hist_all) >= 2)
    ? trend_delta($prod_hist_all[count($prod_hist_all) - 1], $prod_hist_all[count($prod_hist_all) - 2])
    : null;
$h_score_prev = habit_score_prev_week($pdo, $uid);
$h_score_delta = trend_delta($h_score, $h_score_prev);
$yesterday_weekday_label = date('l', strtotime('-7 days'));
$tasks_prev = tasks_completed_on($pdo, $uid, date('Y-m-d', strtotime('-7 days')));
$tasks_delta = trend_delta_count($tasks['done'], $tasks_prev, $yesterday_weekday_label);

// Heatmap — last 84 days
$stmt = $pdo->prepare("SELECT gl.log_date, COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
    WHERE g.user_id=? AND gl.status='done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 83 DAY)
    GROUP BY gl.log_date");
$stmt->execute([$uid]);
$heat_by_date = [];
foreach ($stmt->fetchAll() as $r) $heat_by_date[$r['log_date']] = (int)$r['c'];
$heat_days = [];
$cursor = new DateTime('-83 days');
for ($i = 0; $i < 84; $i++) { $heat_days[] = $cursor->format('Y-m-d'); $cursor->modify('+1 day'); }
$max_heat = max(1, max($heat_by_date ?: [0]));

$current_wellness = wellness_score($pdo, $uid);
$wellness_trend = wellness_score_weekly($pdo, $uid, 8);

$checkin_compare = weekly_checkin_bars_compare($pdo, $uid);
$monthly_chart = [];
$wk_start = new DateTime('-34 days');
for ($w = 0; $w < 5; $w++) {
    $wk_end = (clone $wk_start)->modify('+6 days');
    $possible = $active_goal_count * 7;
    $done = 0;
    foreach ($heat_days as $d) {
        if ($d >= $wk_start->format('Y-m-d') && $d <= $wk_end->format('Y-m-d')) $done += $heat_by_date[$d] ?? 0;
    }
    $pct = $possible > 0 ? min(100, round(($done / $possible) * 100)) : 0;
    $monthly_chart[] = ['label' => 'W' . ($w+1), 'pct' => $pct];
    $wk_start->modify('+7 days');
}

$time_alloc = category_time_allocation($pdo, $uid, 7);
$donut_segments = [];
foreach ($time_alloc['categories'] as $r) {
    $donut_segments[$r['name']] = ['value' => (float)$r['minutes'], 'color' => $r['color']];
}
if ($time_alloc['unassigned_minutes'] > 0) {
    $donut_segments['Deep Work (no goal)'] = ['value' => (float)$time_alloc['unassigned_minutes'], 'color' => '#9391F5'];
}
$donut = svg_donut_chart($donut_segments);
$total_minutes_week = $donut['total'];
$total_time_label = $total_minutes_week > 0 ? (intdiv($total_minutes_week, 60) . 'h ' . ($total_minutes_week % 60) . 'm') : '0m';

$wellness_completion_points = wellness_completion_correlation($pdo, $uid, 8);
$focus_completion_points = focus_completion_daily($pdo, $uid, 30);
$focus_stats = focus_session_stats($pdo, $uid, 7);

// Streak Survival Curve + Feature Importance (Engine additions — see
// includes/helpers.php, block appended above daily_quote()).
$survival = streak_survival_curve($pdo, $uid, 21);
$survival_chart = null;
if ($survival) {
    $survival_chart = svg_line_chart(
        $survival['days'],
        ['Streak survival %' => ['actual' => $survival['survival_pct'], 'forecast' => [], 'color' => '#EE8AD1']]
    );
}
$feature_importance = habit_feature_importance($pdo, $uid);

// Burnout risk (Engine C) — read-only, same table burnout.php reads.
$stmt = $pdo->prepare("SELECT * FROM burnout_predictions WHERE user_id=?");
$stmt->execute([$uid]);
$burnout_row = $stmt->fetch();
$burnout_payload = $burnout_row ? json_decode($burnout_row['payload'], true) : null;

// Productivity trend chart (with its own collapsible model-performance table)
if (!$habit || $habit['status'] !== 'ok') {
    $prod_trend_content = null;
    $prod_trend_empty_icon = '🔮';
    $prod_trend_empty_message = $habit ? htmlspecialchars($habit['message']) : 'No forecast yet. Run <code>python ml/train_model.py</code> once from the project folder, then refresh this page.';
} elseif (!$habit_ready) {
    $prod_trend_content = null;
    $prod_trend_empty_icon = '🔄';
    $prod_trend_empty_message = 'This forecast predates the Productivity Score update — go to Analyse → Finance and click <strong>↻ Retrain now</strong> (or run <code>python ml/train_model.py</code>) to refresh it.';
} else {
    $wlabels = [];
    $n_actual_weeks = count($habit['history']['weeks']);
    for ($i = 0; $i < $n_actual_weeks; $i++) $wlabels[] = 'Wk ' . ($i + 1);
    $n_fc_weeks = count($habit['forecast']['completion_pct']);
    for ($i = 0; $i < $n_fc_weeks; $i++) $wlabels[] = '+' . ($i + 1);
    $prod_trend_chart = svg_line_chart($wlabels, [
        'Productivity score' => ['actual' => $habit['history']['productivity_score'], 'forecast' => $habit['forecast']['productivity_score'], 'color' => '#9391F5', 'ci_lower' => $habit['forecast']['productivity_score_lower'] ?? [], 'ci_upper' => $habit['forecast']['productivity_score_upper'] ?? []],
        'Completion %' => ['actual' => $habit['history']['completion_pct'], 'forecast' => $habit['forecast']['completion_pct'], 'color' => '#EE8AD1', 'ci_lower' => $habit['forecast']['completion_pct_lower'] ?? [], 'ci_upper' => $habit['forecast']['completion_pct_upper'] ?? []],
    ]);
    ob_start(); ?>
    <div class="chart-draw"><?= $prod_trend_chart ?></div>
    <details class="model-details">
        <summary>Why these numbers? (model performance)</summary>
        <div style="overflow-x:auto;">
        <table class="model-table" style="min-width:420px;">
            <tr><th>Metric</th><th>Linear Regression</th><th>ARIMA</th><th>Winner</th></tr>
            <tr>
                <td>Productivity score (MAE / RMSE / Accuracy)</td>
                <?php foreach (['linear_trend', 'arima'] as $m): $sc = $habit['model']['productivity_scores'][$m] ?? null; ?>
                <td><?= $sc ? $sc['mae'] . ' / ' . $sc['rmse'] . ' / ' . ($sc['accuracy'] !== null ? $sc['accuracy'] . '%' : '—') : '—' ?></td>
                <?php endforeach; ?>
                <td><?= forecast_method_label($habit['model']['productivity_method']) ?></td>
            </tr>
            <tr>
                <td>Completion % (MAE / RMSE / Accuracy)</td>
                <?php foreach (['linear_trend', 'arima'] as $m): $sc = $habit['model']['completion_scores'][$m] ?? null; ?>
                <td><?= $sc ? $sc['mae'] . ' / ' . $sc['rmse'] . ' / ' . ($sc['accuracy'] !== null ? $sc['accuracy'] . '%' : '—') : '—' ?></td>
                <?php endforeach; ?>
                <td><?= forecast_method_label($habit['model']['completion_method']) ?></td>
            </tr>
        </table>
        </div>
        <?php if (isset($habit['model']['habit_accuracy']) && $habit['model']['habit_accuracy'] !== null): ?>
        <p style="font-size:13px; margin-top:8px;">Overall habit forecast accuracy: <strong><?= $habit['model']['habit_accuracy'] ?>%</strong> <span style="color:var(--ink-soft); font-size:11.5px;">(average of the winning completion-rate and Productivity Score models' own backtest accuracy)</span></p>
        <?php endif; ?>
    </details>
    <?php $prod_trend_content = ob_get_clean();
    $prod_trend_empty_icon = null; $prod_trend_empty_message = null;
}

// Lightweight, computed-not-raw insight sentences for the Productivity tab
$prod_insights = [];
if ($prod_delta) $prod_insights[] = "Your Productivity Score is " . $prod_delta['text'] . ".";
if ($top_productive_day) $prod_insights[] = "{$top_productive_day['dname']} is your strongest day, averaging {$top_productive_day['score']}/100.";
if ($least_productive_day && $least_productive_day !== $top_productive_day) $prod_insights[] = "{$least_productive_day['dname']} tends to be your slowest, averaging {$least_productive_day['score']}/100 — a lighter day to plan around, not a failure.";
$focus_r = pearson_r($focus_completion_points);
if ($focus_r !== null && abs($focus_r) >= 0.3) {
    $prod_insights[] = "Focus session time and habit completion move together this month (r = " . round($focus_r, 2) . ") — " . ($focus_r > 0 ? 'more focus time tends to come with a higher completion rate.' : 'more logged focus time hasn\'t translated into more check-ins yet.');
}

/* ============================================================
   FINANCE data (ported from the old finance.php Goals + Forecast tabs).
   Transaction entry ("This Month") stays on finance.php.
   ============================================================ */
$finance = get_forecast($pdo, $uid, 'finance');
$fin_goals = financial_goals_with_forecast($pdo, $uid);
$health = financial_health_score($pdo, $uid, $fin_goals);

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['goal_added'])): ?><div class="alert alert-success">Goal added 🎯</div><?php endif; ?>
<?php if (isset($_GET['contributed'])): ?><div class="alert alert-success">Contribution added 💪</div><?php endif; ?>

<div class="page-lead">
    <h1 style="font-size:26px; margin-bottom:4px;">Analyse</h1>
    <p style="color:var(--ink-soft); font-weight:600; font-size:14px; max-width:640px;">Three lenses on the same data: how productively you're working, how consistently you're building habits, and how your money is moving — each with its own forecast.</p>
</div>

<div class="tab-group" id="analyseTabs">
<div class="seg-tabs seg-tabs-lg" id="analyseTabTriggers">
    <button class="active" data-tab-target="productivity"><span class="tab-ico">⚡</span> Productivity</button>
    <button data-tab-target="habits"><span class="tab-ico">🌱</span> Habits</button>
    <button data-tab-target="finance"><span class="tab-ico">💰</span> Finance</button>
</div>

<!-- ============================================================
     PRODUCTIVITY
     ============================================================ -->
<div class="tab-panel active" data-tab-panel="productivity">

<div class="section-title"><h2>Productivity overview</h2></div>
<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Productivity score
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">0–100, computed weekly as 60% completion rate + 40% time invested (real Focus Session minutes where logged, otherwise your per-goal time estimate). Based on your actual check-ins, not a forecast.</span></span>
        </h4>
        <?php $prod_hist = $habit_ready ? $habit['history']['productivity_score'] : []; $cur_prod = !empty($prod_hist) ? end($prod_hist) : null; ?>
        <div class="stat-value"><?php if ($cur_prod !== null): ?><span class="count-up" data-target="<?= $cur_prod ?>" data-suffix="/100">0/100</span><?php else: ?>—<?php endif; ?></div>
        <?= trend_badge($prod_delta) ?>
    </div>
    <div class="bento-cell anim-in">
        <h4>Tasks completed today</h4>
        <div class="stat-value"><?= $tasks['done'] ?>/<?= $tasks['total'] ?></div>
        <?= trend_badge($tasks_delta) ?>
    </div>
    <div class="bento-cell anim-in good">
        <h4>🏆 Top productive day</h4>
        <?php if ($top_productive_day): ?>
            <div class="stat-value-sm"><?= htmlspecialchars($top_productive_day['dname']) ?></div>
            <div class="caption" style="margin-top:2px;"><?= $top_productive_day['score'] ?>/100 average score</div>
        <?php else: ?>
            <p class="caption">Check in on a few goals to see this here.</p>
        <?php endif; ?>
    </div>
    <div class="bento-cell anim-in attention">
        <h4>🌱 Least productive day</h4>
        <?php if ($least_productive_day): ?>
            <div class="stat-value-sm"><?= htmlspecialchars($least_productive_day['dname']) ?></div>
            <div class="caption" style="margin-top:2px;"><?= $least_productive_day['score'] ?>/100 average score</div>
        <?php else: ?>
            <p class="caption">Needs a couple more weeks of check-ins across different days.</p>
        <?php endif; ?>
    </div>
</div>

<div class="bento-grid">
<?= chart_card([
    'title' => 'Weekly rhythm — productivity by day',
    'info' => 'Average completion score for each weekday over the last 8 weeks, all seven days at once — actual data, not forecast.',
    'span' => 2,
    'content' => empty($weekday_scores) ? null : svg_radar_chart($weekday_order, [
        'Avg. score' => ['values' => $radar_week_values, 'color' => '#9391F5'],
    ], 100, 320),
    'empty_message' => 'Check in on a few goals to see this here.',
]) ?>
<?php
if (empty($donut_segments)) {
    $time_alloc_content = null;
} else {
    ob_start(); ?>
    <div class="donut-wrap">
        <div class="donut-figure">
            <?= $donut['svg'] ?>
            <div class="donut-center-label">
                <div class="dc-num"><?= $total_time_label ?></div>
                <div class="dc-label">this week</div>
            </div>
        </div>
        <div class="donut-legend">
            <?php foreach ($donut['legend'] as $name => $seg): ?>
            <div class="dl-row">
                <span class="dl-dot" style="background:<?= $seg['color'] ?>;"></span>
                <span><?= htmlspecialchars($name) ?></span>
                <span class="dl-pct"><?= $seg['pct'] ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php $time_alloc_content = ob_get_clean();
}
echo chart_card([
    'title' => 'Time allocation by category',
    'info' => 'Minutes invested per category this week — real Focus Session time when linked to a goal, plus estimated minutes for the rest.',
    'span' => 2,
    'content' => $time_alloc_content,
    'empty_message' => 'Check in on a goal or log a focus session this week to see this here.',
]);
?>
</div>

<div class="section-title"><h2>Productivity forecast</h2></div>
<div class="bento-grid">
<?= chart_card([
    'title' => 'Productivity trend — actual &amp; forecast',
    'info' => 'Backtested across two candidate models (Linear Regression, ARIMA) on your own history. Solid = actual. Dashed = the next two weeks, projected. The shaded band is a 95% confidence interval from the winning model\'s own backtest error.',
    'span' => 4,
    'content' => $prod_trend_content,
    'chart_width' => $prod_trend_content ? 640 : null,
    'empty_icon' => $prod_trend_empty_icon ?? '🔮',
    'empty_message' => $prod_trend_empty_message ?? '',
    'footer' => '<span style="font-size:11.5px; color:var(--ink-soft);">Model comparison (MAE/RMSE/Accuracy) inside "Why these numbers?" above ↑</span>',
]) ?>
</div>

<div class="section-title"><h2>Focus</h2></div>
<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Focus sessions — last 7 days</h4>
        <div class="tracker-row"><span>Sessions</span><strong><?= $focus_stats['sessions'] ?></strong></div>
        <div class="tracker-row"><span>Avg. focus time</span><strong><?= $focus_stats['avg_minutes'] ?> min</strong></div>
        <div class="tracker-row"><span>Success rate</span><strong><?= $focus_stats['success_rate'] ?>%</strong></div>
        <a href="focus.php" class="btn btn-ghost btn-sm btn-block" style="margin-top:12px;">Go to Focus Sessions →</a>
    </div>
    <?= chart_card([
        'title' => 'Focus time vs. completion — last 30 days',
        'info' => 'One dot per day: minutes spent in a Focus Session against that day\'s habit check-in completion %, with a fitted trend line. Actual data only.',
        'span' => 3,
        'content' => count($focus_completion_points) < 3 ? null : svg_scatter_chart($focus_completion_points, 'Focus minutes', 'Completion %', '#9FB6F5', 900, 220),
        'chart_width' => 900,
        'empty_message' => 'Log a few Focus Sessions to see this correlation.',
    ]) ?>
</div>

<div class="section-title"><h2>Insights</h2></div>
<div class="card anim-in">
    <?php if (empty($prod_insights)): ?>
        <p class="caption">Check in on a few goals and log some Focus Sessions to unlock productivity insights.</p>
    <?php else: foreach ($prod_insights as $ins): ?>
    <div class="insight-card"><div class="ins-ico">📈</div><p><?= htmlspecialchars($ins) ?></p></div>
    <?php endforeach; endif; ?>
</div>

</div><!-- /productivity -->

<!-- ============================================================
     HABITS
     ============================================================ -->
<div class="tab-panel" data-tab-panel="habits">

<?php if ($burnout_payload && $burnout_payload['risk_label'] !== 'low'): $bl = $burnout_payload['risk_label']; ?>
<div class="alert alert-error anim-in" style="display:flex; align-items:center; gap:10px; justify-content:space-between; flex-wrap:wrap; opacity:<?= $bl === 'high' ? '1' : '0.85' ?>;">
    <span>⚠ <strong><?= $bl === 'high' ? 'High' : 'Medium' ?> burnout risk</strong> — <?= round($burnout_payload['risk_score'] * 100) ?>% probability. See the Mood &amp; Wellness section below for why.</span>
    <a href="#" onclick="document.querySelector('[data-tab-target=habits]').scrollIntoView(); return false;" class="btn btn-ghost btn-sm">View details ↓</a>
</div>
<?php endif; ?>

<div class="section-title"><h2>Habit overview</h2></div>
<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Habit score
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Your 4-week rolling completion rate — sustained consistency, independent of time invested. Computed from your actual check-ins.</span></span>
        </h4>
        <div class="stat-value"><?php if ($h_score !== null): ?><span class="count-up" data-target="<?= $h_score ?>" data-suffix="/100">0/100</span><?php else: ?>—<?php endif; ?></div>
        <?= trend_badge($h_score_delta) ?>
    </div>
    <div class="bento-cell anim-in">
        <h4>Current streak</h4>
        <div class="stat-value"><span class="count-up" data-target="<?= $best_streak['days'] ?>" data-suffix=" days">0 days</span></div>
        <?php if ($best_streak['title']): ?><div class="caption" style="margin-top:4px;"><?= htmlspecialchars($best_streak['title']) ?></div><?php endif; ?>
    </div>
    <div class="bento-cell anim-in">
        <h4>Personal best</h4>
        <div class="stat-value"><span class="count-up" data-target="<?= $best_streak_ever ?>" data-suffix=" days">0 days</span></div>
        <div class="caption" style="margin-top:4px;">🏆 longest streak ever run</div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Weekly goal progress
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">This week's check-ins completed vs. possible, across all active goals, against a 90% target (not 100% — one missed day shouldn't read as failure).</span></span>
        </h4>
        <div style="display:flex; align-items:center; gap:10px; margin-top:6px;">
            <div class="tracker-bar" style="margin:0; height:14px;"><span class="grow-in" style="width:<?= $goal_progress['pct'] ?>%; background: var(--lime-deep);"></span></div>
        </div>
        <strong style="white-space:nowrap; margin-top:6px; display:block;"><?= $goal_progress['pct'] ?>% <span style="color:var(--ink-soft); font-weight:600;">/ <?= $goal_progress['target'] ?>% goal</span></strong>
    </div>
</div>

<div class="section-title"><h2>Habit progress</h2></div>
<div class="bento-grid">
<?php
ob_start(); ?>
<div class="heatmap">
    <?php foreach ($heat_days as $d): $c = $heat_by_date[$d] ?? 0; ?>
    <div class="hm-cell" style="background:<?= heat_color($c, $max_heat) ?>" title="<?= $d ?>: <?= $c ?> check-in<?= $c==1?'':'s' ?>"></div>
    <?php endforeach; ?>
</div>
<div class="heatmap-legend">
    Less <div class="hm-cell" style="background:#EFEFEF"></div><div class="hm-cell" style="background:#EEF9F1"></div><div class="hm-cell" style="background:#DDF5E5"></div><div class="hm-cell" style="background:#B9F0D3"></div><div class="hm-cell" style="background:#8FAE8B"></div> More
</div>
<?php $heatmap_content = ob_get_clean();
echo chart_card(['title' => 'Habit heatmap · last 12 weeks', 'span' => 4, 'content' => $heatmap_content]);
?>
</div>

<div class="bento-grid">
<?= chart_card([
    'title' => 'This week vs. last week',
    'info' => 'Check-ins per weekday, this week alongside the same weekday last week.',
    'span' => 2,
    'content' => svg_grouped_bar_chart($checkin_compare['labels'], [
        'Last week' => ['values' => $checkin_compare['last_week'], 'color' => '#DCDBFB'],
        'This week' => ['values' => $checkin_compare['this_week'], 'color' => 'var(--lavender-deep)'],
    ]),
    'chart_width' => 640,
]) ?>
<?php
ob_start(); ?>
<div class="chart-bars">
    <?php foreach ($monthly_chart as $m): ?>
    <div class="cb-col">
        <div class="cb-bar" style="height:<?= max(6,$m['pct']) ?>%; background:var(--pink-deep);"></div>
        <div class="cb-label"><?= $m['label'] ?> · <?= $m['pct'] ?>%</div>
    </div>
    <?php endforeach; ?>
</div>
<?php echo chart_card(['title' => 'Last 5 weeks · completion %', 'span' => 2, 'content' => ob_get_clean()]); ?>
</div>

<div class="bento-grid">
<?php
$cat_radar_content = null;
if (count($cat_perf) >= 3) {
    $cat_radar_content = svg_radar_chart($radar_axes, [
        'Last week' => ['values' => $radar_last_week, 'color' => '#B9BFF5'],
        'This week' => ['values' => $radar_this_week, 'color' => '#9391F5'],
    ], 100);
} elseif (!empty($cat_perf)) {
    ob_start(); ?>
    <div class="chart-bars">
        <?php foreach ($cat_perf as $cp): ?>
        <div class="cb-col">
            <div class="cb-bar" style="height:<?= max(6,$cp['rate_pct']) ?>%; background:<?= htmlspecialchars($cp['color']) ?>;"></div>
            <div class="cb-label"><?= htmlspecialchars(short_label($cp['name'], 11)) ?> · <?= $cp['rate_pct'] ?>%</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php $cat_radar_content = ob_get_clean();
}
echo chart_card([
    'title' => 'Category comparison — this week vs. last week',
    'info' => 'Completion % for every category with at least one active goal, this week\'s shape plotted against last week\'s.',
    'span' => 4,
    'content' => $cat_radar_content,
    'empty_message' => 'Add a goal in any category to see this comparison.',
]);
?>
</div>

<div class="section-title"><h2>Streaks 🔥</h2></div>
<div class="bento-grid">
<?= chart_card([
    'title' => 'Streak survival curve',
    'info' => 'Of every streak you\'ve run across all your habits, what % reached at least day N — a Kaplan-Meier-style curve computed straight from your check-in history, no black box.',
    'span' => 3,
    'note' => $survival ? "Based on {$survival['n_streaks']} streak(s) in your history · longest run so far: {$survival['longest']} day(s). A currently-active streak is counted at its length so far." : null,
    'content' => $survival_chart,
    'chart_width' => $survival_chart ? 640 : null,
    'empty_icon' => '📉',
    'empty_message' => 'Log check-ins for a couple of weeks — this needs at least 3 completed or ongoing streaks to plot.',
]) ?>
<div class="bento-cell anim-in" style="justify-content:center;">
    <h4>Streak snapshot</h4>
    <div class="tracker-row"><span>Current</span><strong><?= $best_streak['days'] ?> days</strong></div>
    <div class="tracker-row"><span>Personal best</span><strong><?= $best_streak_ever ?> days</strong></div>
    <?php if ($survival): ?>
    <div class="tracker-row"><span>Streaks logged</span><strong><?= $survival['n_streaks'] ?></strong></div>
    <div class="tracker-row"><span>Longest run</span><strong><?= $survival['longest'] ?> days</strong></div>
    <?php endif; ?>
</div>
</div>

<div class="section-title"><h2>Mood &amp; wellness</h2></div>
<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Wellness score
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">A blend of mood, sleep and stress over the last 7 days, same formula the Mood Tracker itself shows.</span></span>
        </h4>
        <div class="stat-value"><?= $current_wellness !== null ? $current_wellness . '/100' : '—' ?></div>
        <a href="mood.php" class="btn btn-ghost btn-sm btn-block" style="margin-top:10px;">Log today's mood →</a>
    </div>
    <?php
    $wellness_trend_content = (count($wellness_trend['labels']) < 2) ? null : svg_line_chart($wellness_trend['labels'], [
        'Wellness score' => ['actual' => $wellness_trend['scores'], 'forecast' => [], 'color' => '#EE8AD1'],
    ], 640, 220);
    echo chart_card([
        'title' => 'Mood &amp; wellness trend — last 8 weeks',
        'info' => 'Your weekly wellness score over time. Actual data only, not a forecast.',
        'span' => 3,
        'content' => $wellness_trend_content,
        'chart_width' => 640,
        'empty_message' => 'Check in on the Mood Tracker for a couple of weeks to see this trend.',
    ]);
    ?>
</div>

<div class="bento-grid">
<?= chart_card([
    'title' => 'Wellness vs. habit consistency',
    'info' => 'One dot per week: your wellness score against how much of your habit check-ins you completed that same week, with a fitted trend line.',
    'span' => 4,
    'content' => count($wellness_completion_points) < 3 ? null : svg_scatter_chart($wellness_completion_points, 'Wellness score', 'Completion %', '#EE8AD1', 1100, 220),
    'chart_width' => 1100,
    'empty_message' => 'Log moods and check-ins across a few weeks to see this correlation.',
]) ?>
</div>

<?php if ($burnout_payload): $pct = round($burnout_payload['risk_score'] * 100); $bl = $burnout_payload['risk_label'];
    $label_color = ['low' => 'var(--good-ink)', 'medium' => 'var(--attention-ink)', 'high' => 'var(--attention)'][$bl] ?? 'var(--ink)';
    $label_text = ['low' => 'Low risk', 'medium' => 'Medium risk', 'high' => 'High risk'][$bl] ?? $bl;
?>
<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Burnout risk
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">A RandomForestClassifier (see ml/README_BURNOUT.md) trained on an external wellness benchmark, scored on your last <?= $burnout_payload['days_of_history'] ?> logged mood days. <?= $burnout_payload['data_completeness_pct'] ?>% of the inputs are your own data — the rest is filled in from the benchmark average, flagged below.</span></span>
        </h4>
        <div class="stat-value" style="color:<?= $label_color ?>;"><?= $pct ?>%</div>
        <div class="caption" style="margin-top:4px; font-weight:700; color:<?= $label_color ?>;"><?= $label_text ?></div>
    </div>
    <div class="bento-cell span-3 anim-in">
        <h4>Why</h4>
        <div class="grid-2-col" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:6px;">
            <div>
                <p style="font-weight:700; font-size:12.5px; color:var(--attention); margin-bottom:6px;">⚠ Risk factors</p>
                <?php if (empty($burnout_payload['risk_factors'])): ?><p class="caption">None standing out.</p><?php endif; ?>
                <?php foreach ($burnout_payload['risk_factors'] as $f): ?>
                <p class="caption" style="margin-bottom:6px;"><?= htmlspecialchars($f['label']) ?>: <?= htmlspecialchars((string)round($f['value'], 1)) ?> vs. <?= $f['from_benchmark'] ? 'benchmark' : 'your' ?> avg <?= htmlspecialchars((string)$f['benchmark_avg']) ?><?= $f['from_benchmark'] ? ' <em>(not tracked by this app)</em>' : '' ?></p>
                <?php endforeach; ?>
            </div>
            <div>
                <p style="font-weight:700; font-size:12.5px; color:var(--good-ink); margin-bottom:6px;">✓ Protective factors</p>
                <?php if (empty($burnout_payload['protective_factors'])): ?><p class="caption">None standing out yet.</p><?php endif; ?>
                <?php foreach ($burnout_payload['protective_factors'] as $f): ?>
                <p class="caption" style="margin-bottom:6px;"><?= htmlspecialchars($f['label']) ?>: <?= htmlspecialchars((string)round($f['value'], 1)) ?> vs. <?= $f['from_benchmark'] ? 'benchmark' : 'your' ?> avg <?= htmlspecialchars((string)$f['benchmark_avg']) ?><?= $f['from_benchmark'] ? ' <em>(not tracked by this app)</em>' : '' ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="bento-grid">
    <div class="bento-cell span-4 anim-in" style="align-items:flex-start;">
        <h4>Burnout risk</h4>
        <p class="caption">No score yet — log a few days on the Mood Tracker, then have <code>python ml/burnout_model.py</code> run once (or wire up a retrain button, same idea as the Finance forecast).</p>
    </div>
</div>
<?php endif; ?>

<div class="section-title"><h2>Habit prediction</h2></div>
<div class="bento-grid">
    <div class="bento-cell span-4 anim-in">
        <h4>Feature importance — what predicts your completion rate
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Each factor's |Pearson r| against your weekly/daily completion %, ranked — the same "how much does this matter" idea as a trained model's feature importance, computed directly instead of learned, so it's exact even with a small history.</span></span>
        </h4>
        <?php if (empty($feature_importance)): ?>
            <p class="caption">Log moods and Focus Sessions alongside check-ins for a few weeks to see what actually moves your completion rate.</p>
        <?php else: foreach ($feature_importance as $f):
            $bar_color = $f['r'] >= 0 ? '#63C48C' : '#E4685A';
        ?>
        <div style="margin-top:14px;">
            <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:700; margin-bottom:5px;">
                <span><?= htmlspecialchars($f['label']) ?></span>
                <span style="color:var(--ink-soft); font-weight:600;">r = <?= round($f['r'], 2) ?> (<?= $f['r'] >= 0 ? '+' : '−' ?>)</span>
            </div>
            <div class="tracker-bar" style="margin:0; height:10px;"><span class="grow-in" style="width:<?= round($f['abs_r'] * 100) ?>%; background:<?= $bar_color ?>;"></span></div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="section-title"><h2>Insights</h2></div>
<div class="card anim-in" style="margin-bottom:8px;">
    <?php
    $report_lines = [];
    $report_lines[] = "You've logged {$total_checkins} check-in" . ($total_checkins == 1 ? '' : 's') . " across {$active_goal_count} active goal" . ($active_goal_count == 1 ? '' : 's') . ", for a {$consistency}% overall consistency score since you started tracking.";
    if ($best_cat) {
        $report_lines[] = "{$best_cat['name']} is your strongest category this week at {$best_cat['rate_pct']}% completion" . ($worst_cat && $worst_cat['id'] !== $best_cat['id'] ? ", while {$worst_cat['name']} is the one that could use more attention at {$worst_cat['rate_pct']}%." : '.');
    }
    if ($top_day) $report_lines[] = "You're most consistent on {$top_day['dname']}s ({$top_day['c']} check-ins logged all-time).";
    if ($current_wellness !== null) {
        $trend_word = 'steady';
        if (count($wellness_trend['scores']) >= 2) {
            $delta = end($wellness_trend['scores']) - $wellness_trend['scores'][count($wellness_trend['scores']) - 2];
            $trend_word = $delta > 3 ? 'improving' : ($delta < -3 ? 'dipping' : 'steady');
        }
        $report_lines[] = "Your wellness score is {$current_wellness}/100 and has been {$trend_word} over the last few weeks.";
    } else {
        $report_lines[] = "Log a mood check-in on the Mood Tracker to bring your wellness trend into this report.";
    }
    foreach ($report_lines as $line): ?>
    <div class="insight-card"><div class="ins-ico">📌</div><p><?= htmlspecialchars($line) ?></p></div>
    <?php endforeach; ?>
</div>
<p style="text-align:center; margin-top:4px;"><a href="coach.php" class="btn btn-ghost btn-sm">✨ See your full AI Coach summary →</a></p>

</div><!-- /habits -->

<!-- ============================================================
     FINANCE
     ============================================================ -->
<div class="tab-panel" data-tab-panel="finance">

<div class="section-title">
    <h2>Financial overview</h2>
    <a href="finance.php" class="btn btn-ghost btn-sm">Log a transaction →</a>
</div>

<?php if ($health): ?>
<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Financial Health Score
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">A transparent 0-100 blend: 30% savings consistency (months you ended in the black), 25% spending behaviour (how often expenses outpaced income), 25% average goal progress, 20% income stability — no black-box model, just weighted arithmetic over your own transactions.</span></span>
        </h4>
        <div class="stat-value"><span class="count-up" data-target="<?= $health['score'] ?>" data-suffix="/100"><?= $health['score'] ?>/100</span></div>
    </div>
    <?php foreach ($health['breakdown'] as $label => $val): ?>
    <div class="bento-cell anim-in">
        <h4><?= ucwords(str_replace('_', ' ', $label)) ?></h4>
        <div class="stat-value" style="font-size:22px;"><?= $val ?>%</div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-title">
    <h2>Financial goals</h2>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('addFinGoalModal').classList.add('show')">+ Add goal</button>
</div>

<?php if (empty($fin_goals)): ?>
    <div class="card empty-state">
        <div class="em-ico">🎯</div>
        <p>No financial goals yet — set a savings target, emergency fund, or debt-payoff goal to unlock a completion forecast.</p>
    </div>
<?php else: foreach ($fin_goals as $g):
    $f = $g['forecast'];
    $pct = $g['target_amount'] > 0 ? min(100, round(($g['current_amount'] / $g['target_amount']) * 100)) : 0;
    $type_meta = GOAL_TYPE_META[$g['goal_type']] ?? ['label' => 'Savings', 'icon' => '💰'];
    $days_left = (new DateTime('today'))->diff(new DateTime($g['target_date']))->days;
    $is_past_due = new DateTime($g['target_date']) < new DateTime('today');
?>
<div class="card anim-in" style="margin-bottom:20px;">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div>
            <h4 style="margin-bottom:2px;"><?= $type_meta['icon'] ?> <?= htmlspecialchars($g['title']) ?></h4>
            <div style="font-size:12px; color:var(--ink-soft); font-weight:600;">
                <?= $type_meta['label'] ?> · target <?= date('d M Y', strtotime($g['target_date'])) ?>
                (<?= $is_past_due ? 'past due' : $days_left . ' days left' ?>)
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            <?php if ($f['status'] === 'ok'): ?><?= risk_badge_html($f['risk']) ?><?php endif; ?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="openContribute(<?= $g['id'] ?>, '<?= htmlspecialchars(addslashes($g['title'])) ?>')">+ Contribute</button>
            <form method="POST" action="finance.php" onsubmit="return confirm('Delete this goal and its contribution history?');">
                <input type="hidden" name="goal_id" value="<?= $g['id'] ?>">
                <button type="submit" name="delete_fin_goal" class="icon-btn" title="Delete">🗑</button>
            </form>
        </div>
    </div>

    <div style="margin-top:14px;">
        <div class="tracker-bar" style="max-width:100%;"><span class="grow-in" style="width:<?= $pct ?>%; background: var(--sky-deep);"></span></div>
        <div style="display:flex; justify-content:space-between; margin-top:6px; font-size:13px;">
            <span><strong>₹<?= number_format($g['current_amount'], 0) ?></strong> of ₹<?= number_format($g['target_amount'], 0) ?> (<?= $pct ?>%)</span>
            <span style="color:var(--ink-soft);">₹<?= number_format(max(0, $g['target_amount'] - $g['current_amount']), 0) ?> remaining</span>
        </div>
    </div>

    <?php if ($f['status'] === 'achieved'): ?>
        <div class="insight-card" style="margin-top:14px;"><div class="ins-ico">🎉</div><p><?= $f['message'] ?></p></div>
    <?php elseif ($f['status'] === 'no_data'): ?>
        <div class="insight-card" style="margin-top:14px;"><div class="ins-ico">💡</div><p><?= htmlspecialchars($f['message']) ?> Needed: ~₹<?= number_format($f['required_monthly'], 0) ?>/month to hit the deadline.</p></div>
    <?php else: ?>
        <div class="bento-grid" style="margin-top:14px; grid-template-columns:repeat(3,1fr);">
            <div class="bento-cell" style="padding:12px 16px;">
                <h4 style="font-size:11.5px;">Predicted completion</h4>
                <div style="font-weight:800; font-size:15px;"><?= $f['expected_date'] ? date('d M Y', strtotime($f['expected_date'])) : '—' ?></div>
                <div class="caption" style="margin-top:2px;">best: <?= $f['best_date'] ? date('d M', strtotime($f['best_date'])) : '—' ?> · worst: <?= $f['worst_date'] ? date('d M Y', strtotime($f['worst_date'])) : '—' ?></div>
            </div>
            <div class="bento-cell" style="padding:12px 16px;">
                <h4 style="font-size:11.5px;">Current pace</h4>
                <div style="font-weight:800; font-size:15px;">₹<?= number_format($f['avg_monthly'], 0) ?>/mo</div>
                <div class="caption" style="margin-top:2px;">trend: <?= ucfirst($f['velocity']) ?></div>
            </div>
            <div class="bento-cell" style="padding:12px 16px;">
                <h4 style="font-size:11.5px;">Needed to stay on track</h4>
                <div style="font-weight:800; font-size:15px;">₹<?= number_format($f['required_monthly'], 0) ?>/mo</div>
                <div class="caption" style="margin-top:2px;">to hit the deadline</div>
            </div>
        </div>
        <?php foreach ($f['insights'] as $ins): ?>
        <div class="insight-card" style="margin-top:10px;"><div class="ins-ico">📌</div><p><?= htmlspecialchars($ins) ?></p></div>
        <?php endforeach; ?>

        <?php
        $chart = $f['chart'];
        $chart_labels = array_merge($chart['history_labels'], $chart['forecast_labels']);
        $proj_content = empty($chart_labels) ? null : '<div class="chart-draw">' . svg_line_chart($chart_labels, [
            'Balance' => [
                'actual' => $chart['history_values'], 'forecast' => $chart['forecast_values'], 'color' => '#5C8AE6',
                'ci_lower' => $chart['forecast_lower'], 'ci_upper' => $chart['forecast_upper'],
            ],
        ], 900, 200) . '</div>';
        ?>
        <div style="margin-top:16px;">
        <?= chart_card([
            'title' => 'Projected balance vs. target',
            'info' => 'Solid = your logged contributions so far. Dashed = projected at your average monthly pace. The shaded band widens/narrows based on how consistent your contributions have actually been.',
            'note' => 'Target: ₹' . number_format($g['target_amount'], 0) . ' by ' . date('d M Y', strtotime($g['target_date'])) . '.',
            'content' => $proj_content,
            'chart_width' => 700,
            'anim' => false,
        ]) ?>
        </div>

        <div class="card" style="background:var(--offwhite); border:1.5px dashed var(--lightgray); margin-top:14px; padding:16px 18px;">
            <h4 style="text-transform:uppercase; font-size:11.5px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:10px;">🧪 What if? — scenario simulator</h4>
            <div class="form-row" style="align-items:flex-end;">
                <div class="field" style="margin-bottom:0;">
                    <label>Extra ₹ per month</label>
                    <input type="number" min="0" step="50" value="0" class="scenario-extra" data-goal="<?= $g['id'] ?>">
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label>Missed months</label>
                    <input type="number" min="0" max="11" step="1" value="0" class="scenario-missed" data-goal="<?= $g['id'] ?>">
                </div>
                <button type="button" class="btn btn-ghost btn-sm" onclick="runScenario(<?= $g['id'] ?>, <?= $f['avg_monthly'] ?>, <?= $f['remaining_amount'] ?>)">Simulate</button>
            </div>
            <p class="scenario-result" id="scenarioResult<?= $g['id'] ?>" style="font-size:13px; margin-top:10px; font-weight:600;"></p>
        </div>
    <?php endif; ?>
</div>
<?php endforeach; endif; ?>

<div class="section-title"><h2>Financial forecast</h2></div>
<div class="coach-hero anim-in" style="background: linear-gradient(135deg, var(--lavender), var(--sky)); color:var(--ink);">
    <div class="coach-avatar" style="background: var(--ink); color: var(--lime);">🔮</div>
    <div style="flex:1;">
        <p style="color:#3A3A45; margin:0;">Two candidate models — Linear Regression and ARIMA — backtested on your own history; whichever predicted your recent months most accurately is used. No external AI API — every number traces back to <code>ml/forecasting.py</code>.</p>
    </div>
    <button class="btn btn-ghost btn-sm" id="refreshBtn" onclick="refreshForecast()">↻ Retrain now</button>
</div>
<div id="refreshMsg" style="display:none; margin-bottom:18px;"></div>

<?php if (!$finance || $finance['status'] === 'no_data'): ?>
    <div class="card empty-state">
        <div class="em-ico">🔮</div>
        <p><?= $finance ? htmlspecialchars($finance['message']) : 'No forecast yet. Run <code>python ml/train_model.py</code> once from the project folder, then refresh this page.' ?></p>
    </div>

<?php elseif ($finance['status'] === 'benchmark_preview'): ?>
    <div class="benchmark-banner anim-in">
        <h4>🔍 Preview using the benchmark dataset</h4>
        <p><?= htmlspecialchars($finance['message']) ?></p>
    </div>
    <div class="card anim-in">
        <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:14px;">
            Typical spending breakdown
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">From ml/data/finance_benchmark.xlsx — a benchmark dataset, not your own spending yet. It's replaced by your real category split the moment you log a transaction.</span></span>
        </h4>
        <?php $bshares = $finance['benchmark_category_shares']; $bmax = $bshares ? max($bshares) : 1; ?>
        <?php foreach ($bshares as $cat => $pct): ?>
        <div class="tracker-row">
            <span><?= htmlspecialchars($cat) ?></span>
            <div class="tracker-bar"><span class="grow-in" style="width:<?= $bmax > 0 ? round(($pct / $bmax) * 100) : 0 ?>%; background: var(--lavender-deep);"></span></div>
            <strong><?= $pct ?>%</strong>
        </div>
        <?php endforeach; ?>
    </div>

<?php elseif ($finance['status'] !== 'ok'): ?>
    <div class="card empty-state">
        <div class="em-ico">💰</div>
        <p><?= htmlspecialchars($finance['message']) ?></p>
    </div>

<?php else:
    $h = $finance['history'];
    $f = $finance['forecast'];
    $has_margin = isset($h['profit_margin']) && isset($f['profit_margin']);
    $has_cash = isset($h['cash_flow']) && isset($f['cash_flow']);
?>
    <div class="bento-grid">
        <div class="bento-cell anim-in">
            <h4>Projected revenue
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Next month's income, forecast from your logged transactions.</span></span>
            </h4>
            <div class="stat-value"><span class="count-up" data-prefix="₹" data-target="<?= $f['income'][0] ?? 0 ?>">₹0</span></div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Projected expense</h4>
            <div class="stat-value"><span class="count-up" data-prefix="₹" data-target="<?= $f['expense'][0] ?? 0 ?>">₹0</span></div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Projected profit</h4>
            <div class="stat-value"><span class="count-up" data-prefix="₹" data-target="<?= $f['profit'][0] ?? 0 ?>">₹0</span></div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Profit margin
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Projected profit as a percentage of projected revenue next month.</span></span>
            </h4>
            <div class="stat-value"><?php if ($has_margin): ?><span class="count-up" data-target="<?= $f['profit_margin'][0] ?? 0 ?>" data-suffix="%">0%</span><?php else: ?>—<?php endif; ?></div>
        </div>
    </div>

    <div class="bento-grid">
        <div class="bento-cell anim-in">
            <h4>Cash flow (projected, cumulative)
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Your running cash position — the cumulative total of every month's profit added together.</span></span>
            </h4>
            <div class="stat-value <?= ($has_cash && ($f['cash_flow'][0] ?? 0) >= 0) ? '' : 'text-attention' ?>">
                <?php if ($has_cash): ?><span class="count-up" data-prefix="₹" data-target="<?= $f['cash_flow'][0] ?? 0 ?>">₹0</span><?php else: ?>—<?php endif; ?>
            </div>
            <div class="caption" style="margin-top:4px;">end of next month</div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Model used</h4>
            <div style="font-size:14px; font-weight:700; margin-top:6px;"><?= forecast_method_label($finance['model']['expense_method']) ?></div>
            <div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;">MAE ₹<?= $finance['model']['expense_mae'] ?? '—' ?> · RMSE ₹<?= $finance['model']['expense_rmse'] ?? '—' ?></div>
        </div>
        <div class="bento-cell span-2 anim-in" style="justify-content:center;">
            <h4 style="text-transform:uppercase; font-size:12px; color:var(--ink-soft); letter-spacing:.04em;">Forecast summary</h4>
            <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:6px;">
                <tr style="color:var(--ink-soft); font-weight:700; text-align:left;">
                    <th style="padding:4px 6px 8px;">Metric</th><th style="padding:4px 6px 8px;">This month</th><th style="padding:4px 6px 8px;">Next month</th>
                </tr>
                <tr><td style="padding:6px;">Revenue</td><td style="padding:6px;"><?= money(end_val($h['income'])) ?></td><td style="padding:6px; font-weight:700;"><?= money($f['income'][0] ?? 0) ?></td></tr>
                <tr><td style="padding:6px;">Expense</td><td style="padding:6px;"><?= money(end_val($h['expense'])) ?></td><td style="padding:6px; font-weight:700;"><?= money($f['expense'][0] ?? 0) ?></td></tr>
                <tr><td style="padding:6px;">Profit</td><td style="padding:6px;"><?= money(end_val($h['profit'])) ?></td><td style="padding:6px; font-weight:700;"><?= money($f['profit'][0] ?? 0) ?></td></tr>
                <?php if ($has_margin): ?><tr><td style="padding:6px;">Profit margin</td><td style="padding:6px;"><?= end_val($h['profit_margin']) ?>%</td><td style="padding:6px; font-weight:700;"><?= $f['profit_margin'][0] ?? 0 ?>%</td></tr><?php endif; ?>
                <?php if ($has_cash): ?><tr><td style="padding:6px;">Cash flow (cumulative)</td><td style="padding:6px;"><?= money(end_val($h['cash_flow'])) ?></td><td style="padding:6px; font-weight:700;"><?= money($f['cash_flow'][0] ?? 0) ?></td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php
    $labels = array_merge($h['months'], array_fill(0, count($f['income']), ''));
    $fc_count = count($f['income']);
    for ($i = 0; $i < $fc_count; $i++) $labels[count($h['months']) + $i] = '+' . ($i + 1) . 'mo';
    $income_expense_chart = svg_line_chart($labels, [
        'Income' => ['actual' => $h['income'], 'forecast' => $f['income'], 'color' => '#5C8AE6', 'ci_lower' => $f['income_lower'] ?? [], 'ci_upper' => $f['income_upper'] ?? []],
        'Expense' => ['actual' => $h['expense'], 'forecast' => $f['expense'], 'color' => '#EE8AD1', 'ci_lower' => $f['expense_lower'] ?? [], 'ci_upper' => $f['expense_upper'] ?? []],
    ]);
    ob_start(); ?>
    <div class="chart-draw"><?= $income_expense_chart ?></div>
    <details class="model-details">
        <summary>Why these numbers? (model performance)</summary>
        <div style="overflow-x:auto;">
        <table class="model-table">
            <tr><th>Model</th><th>Income MAE</th><th>Income RMSE</th><th>Income Accuracy</th><th>Expense MAE</th><th>Expense RMSE</th><th>Expense Accuracy</th></tr>
            <?php foreach (['linear_trend', 'arima'] as $m):
                $is = $finance['model']['income_scores'][$m] ?? null;
                $es = $finance['model']['expense_scores'][$m] ?? null;
                $is_income_winner = $finance['model']['income_method'] === $m;
                $is_expense_winner = $finance['model']['expense_method'] === $m;
            ?>
            <tr>
                <td><strong><?= forecast_method_label($m) ?></strong></td>
                <td<?= $is_income_winner ? ' class="text-good" style="font-weight:800;"' : '' ?>><?= $is ? '₹' . $is['mae'] : '—' ?><?= $is_income_winner ? ' ✓' : '' ?></td>
                <td><?= $is ? '₹' . $is['rmse'] : '—' ?></td>
                <td><?= ($is && $is['accuracy'] !== null) ? $is['accuracy'] . '%' : '—' ?></td>
                <td<?= $is_expense_winner ? ' class="text-good" style="font-weight:800;"' : '' ?>><?= $es ? '₹' . $es['mae'] : '—' ?><?= $is_expense_winner ? ' ✓' : '' ?></td>
                <td><?= $es ? '₹' . $es['rmse'] : '—' ?></td>
                <td><?= ($es && $es['accuracy'] !== null) ? $es['accuracy'] . '%' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <p style="font-size:11.5px;color:var(--ink-soft);margin-top:10px;">MAE/RMSE/Accuracy come from backtesting: predicting the last few real months using only earlier months, then comparing to what actually happened. ✓ marks the model actually used.</p>
    </details>
    <?php $income_expense_content = ob_get_clean(); ?>
    <div class="bento-grid">
    <?= chart_card([
        'title' => 'Income vs. expenses — actual &amp; forecast',
        'info' => 'Backtested across two candidate models. Whichever predicted recent months more accurately draws the dashed line.',
        'span' => 4,
        'note' => 'Solid = what you actually logged. Dashed = the model\'s projection. The shaded band is a 95% confidence interval from the winning model\'s own backtest error.',
        'content' => $income_expense_content,
        'chart_width' => 640,
    ]) ?>
    </div>

    <?php if ($has_cash):
        $cf_labels = array_merge($h['months'], array_fill(0, count($f['cash_flow']), ''));
        $cf_fc_count = count($f['cash_flow']);
        for ($i = 0; $i < $cf_fc_count; $i++) $cf_labels[count($h['months']) + $i] = '+' . ($i + 1) . 'mo';
        $cash_flow_content = '<div class="chart-draw">' . svg_line_chart($cf_labels, [
            'Cash flow' => ['actual' => $h['cash_flow'], 'forecast' => $f['cash_flow'], 'color' => '#5C7A17', 'ci_lower' => $f['cash_flow_lower'] ?? [], 'ci_upper' => $f['cash_flow_upper'] ?? []],
        ]) . '</div>';
    ?>
    <div class="bento-grid">
    <?= chart_card([
        'title' => 'Cumulative cash flow — actual &amp; forecast',
        'info' => 'Running total of every month\'s profit, added together. Rising = your cash position is building up; falling = draining down.',
        'span' => 4,
        'note' => 'Solid = your running balance so far. Dashed = projected, based on forecast profit.',
        'content' => $cash_flow_content,
        'chart_width' => 640,
    ]) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($finance['model_forecasts_next_month'])): $mf = $finance['model_forecasts_next_month']; ?>
    <details class="model-details" style="margin-bottom:24px;">
        <summary>Forecast by model, side by side (advanced)</summary>
        <div style="overflow-x:auto; margin-top:10px;">
        <table class="model-table" style="min-width:400px;">
            <tr><th>Metric</th><th>Linear Regression</th><th>ARIMA</th></tr>
            <tr><td>Revenue</td><td><?= money($mf['linear_trend']['revenue']) ?></td><td><?= money($mf['arima']['revenue']) ?></td></tr>
            <tr><td>Expense</td><td><?= money($mf['linear_trend']['expense']) ?></td><td><?= money($mf['arima']['expense']) ?></td></tr>
            <tr><td>Profit</td><td><?= money($mf['linear_trend']['profit']) ?></td><td><?= money($mf['arima']['profit']) ?></td></tr>
            <tr><td>Profit margin</td><td><?= $mf['linear_trend']['profit_margin'] ?>%</td><td><?= $mf['arima']['profit_margin'] ?>%</td></tr>
            <tr><td>Cash flow (cumulative)</td><td><?= money($mf['linear_trend']['cash_flow']) ?></td><td><?= money($mf['arima']['cash_flow']) ?></td></tr>
        </table>
        </div>
        <p style="font-size:11.5px; color:var(--ink-soft); margin-top:10px;">The KPI cards above use <strong><?= forecast_method_label($finance['model']['income_method']) ?></strong> for revenue and <strong><?= forecast_method_label($finance['model']['expense_method']) ?></strong> for expense — whichever backtested more accurately.</p>
    </details>
    <?php endif; ?>

    <div class="section-title"><h2>Spending analysis</h2></div>
    <div class="card anim-in" style="margin-bottom:24px; background:var(--offwhite); border:1.5px dashed var(--lightgray);">
        <h4 style="text-transform:uppercase; font-size:12px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:8px;">🔗 How your data connects to the benchmark dataset</h4>
        <p style="font-size:13px; font-weight:500; color:var(--ink-soft); line-height:1.6;">
            You've logged <strong><?= $finance['months_of_history_used'] ?? $finance['months_of_history'] ?> month<?= ($finance['months_of_history_used'] ?? $finance['months_of_history']) == 1 ? '' : 's' ?></strong> of your own transactions. The <strong>"Projected category spend"</strong> chart below blends your own category split with the one learned from the benchmark dataset — weighted
            <strong><?= $finance['user_data_weight_pct'] ?? 100 ?>% your data</strong> and
            <strong><?= 100 - ($finance['user_data_weight_pct'] ?? 100) ?>% benchmark</strong> right now (reaches 100% your own data after 6 months).
            The <strong>Income vs. Expense forecast</strong> above uses <em>only</em> your own numbers — the benchmark dataset never influences your actual revenue/expense projections, only this category breakdown.
        </p>
    </div>
    <div class="bento-grid">
        <div class="bento-cell span-2 anim-in">
            <h4>Projected category spend — next month</h4>
            <?php $cats = $finance['category_forecast_next_month']; $cmax = $cats ? max($cats) : 1; ?>
            <?php foreach ($cats as $cat => $amt): $pct = $cmax > 0 ? round(($amt / $cmax) * 100) : 0; ?>
            <div class="tracker-row">
                <span><?= htmlspecialchars($cat) ?></span>
                <div class="tracker-bar"><span class="grow-in" style="width:<?= $pct ?>%; background: var(--pink-deep);"></span></div>
                <strong>₹<?= number_format($amt, 0) ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        $share_axes = []; $share_user = []; $share_bench = [];
        if (!empty($finance['used_kaggle_benchmark']) && !empty($finance['category_shares_benchmark_pct'])) {
            $user_shares = $finance['category_shares_user_pct'] ?? [];
            $bench_shares = $finance['category_shares_benchmark_pct'];
            $top_cats = array_slice(array_keys($bench_shares), 0, 6);
            foreach ($top_cats as $cat) { $share_axes[] = $cat; $share_user[] = $user_shares[$cat] ?? 0; $share_bench[] = $bench_shares[$cat]; }
        }
        $spend_shape_content = (count($share_axes) >= 3) ? svg_radar_chart($share_axes, [
            'Benchmark' => ['values' => $share_bench, 'color' => '#C7D8FF'],
            'Your spending' => ['values' => $share_user, 'color' => '#5C8AE6'],
        ], max(30, max(array_merge($share_user, $share_bench)) * 1.1)) : null;
        ?>
        <?= chart_card([
            'title' => 'Your spending shape vs. benchmark',
            'info' => 'Your own category split (%) plotted against the benchmark dataset\'s typical split — the comparison behind the blended chart on the left.',
            'span' => 2,
            'content' => $spend_shape_content,
            'empty_message' => 'Needs a few benchmark categories to compare against.',
        ]) ?>
    </div>

    <div class="section-title"><h2>Financial insights</h2></div>
    <div class="card anim-in">
        <?php foreach ($finance['insights'] as $ins): ?>
        <div class="insight-card"><div class="ins-ico">📈</div><p><?= htmlspecialchars($ins) ?></p></div>
        <?php endforeach; ?>
    </div>

<?php if ($finance && isset($finance['_generated_at'])): ?>
<p style="font-size:11.5px; color:var(--ink-soft); text-align:right; margin-top:14px;">Last trained: <?= date('d M Y, g:i a', strtotime($finance['_generated_at'])) ?></p>
<?php endif; ?>
<?php endif; ?>

</div><!-- /finance -->

</div><!-- /analyseTabs -->

<!-- Add Financial Goal Modal (kept here since the Goals card lives on this page now; posts to finance.php's existing handler) -->
<div class="modal-overlay" id="addFinGoalModal">
    <div class="modal-box">
        <h3>New financial goal</h3>
        <form method="POST" action="finance.php">
            <div class="field">
                <label>Goal title</label>
                <input type="text" name="title" placeholder="e.g. Emergency fund" required>
            </div>
            <div class="field">
                <label>Goal type</label>
                <select name="goal_type">
                    <?php foreach (GOAL_TYPE_META as $key => $meta): ?>
                    <option value="<?= $key ?>"><?= $meta['icon'] ?> <?= $meta['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="field">
                    <label>Target amount (₹)</label>
                    <input type="number" name="target_amount" step="0.01" min="1" required>
                </div>
                <div class="field">
                    <label>Already saved (₹, optional)</label>
                    <input type="number" name="starting_amount" step="0.01" min="0" value="0">
                </div>
            </div>
            <div class="field">
                <label>Target date</label>
                <input type="date" name="target_date" min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="modal-close-row">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addFinGoalModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_fin_goal" class="btn btn-primary">Save goal</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Contribution Modal (posts to finance.php's existing handler) -->
<div class="modal-overlay" id="addContributionModal">
    <div class="modal-box">
        <h3>Add contribution — <span id="contribGoalTitle"></span></h3>
        <form method="POST" action="finance.php">
            <input type="hidden" name="goal_id" id="contribGoalId">
            <div class="form-row">
                <div class="field">
                    <label>Amount (₹)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required>
                </div>
                <div class="field">
                    <label>Date</label>
                    <input type="date" name="contributed_at" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="modal-close-row">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addContributionModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_contribution" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
// The Analyse page's top-level tab switcher (seg-tabs-lg) drives the SAME
// .tab-group / .tab-panel mechanism app.js already wires up for the
// pill-style .seg-tabs — it listens for [data-tab-target] clicks anywhere,
// so no separate JS is needed here, just the matching markup.

// Scenario Simulator — identical logic to the one that used to live on
// finance.php's Goals tab, moved here since the Goals cards live here now.
function runScenario(goalId, avgMonthly, remaining) {
    const extraInput = document.querySelector(`.scenario-extra[data-goal="${goalId}"]`);
    const missedInput = document.querySelector(`.scenario-missed[data-goal="${goalId}"]`);
    const extra = Math.max(0, parseFloat(extraInput.value) || 0);
    const missed = Math.max(0, Math.min(11, parseInt(missedInput.value) || 0));
    const rate = avgMonthly + extra;
    const resultEl = document.getElementById('scenarioResult' + goalId);

    if (rate <= 0) {
        resultEl.textContent = "At ₹0/month you'll never reach this goal — add a contribution rate above zero.";
        return;
    }

    let balance = 0, month = 0;
    while (balance < remaining && month < 600) {
        month++;
        if (month <= missed) continue;
        balance += rate;
    }
    const finishDate = new Date();
    finishDate.setMonth(finishDate.getMonth() + month);
    const baseMonthsNeeded = Math.ceil(remaining / avgMonthly);
    const deltaMonths = baseMonthsNeeded - month;
    let deltaText = '';
    if (deltaMonths > 0) deltaText = ` — ${deltaMonths} month${deltaMonths === 1 ? '' : 's'} sooner than your current pace.`;
    else if (deltaMonths < 0) deltaText = ` — ${Math.abs(deltaMonths)} month${Math.abs(deltaMonths) === 1 ? '' : 's'} later than your current pace.`;
    else deltaText = ' — same as your current pace.';

    resultEl.textContent = `At ₹${rate.toLocaleString('en-IN')}/month${missed > 0 ? ` (with ${missed} missed month${missed === 1 ? '' : 's'})` : ''}, you'd reach this goal around ${finishDate.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })}${deltaText}`;
}

function openContribute(goalId, title) {
    document.getElementById('contribGoalId').value = goalId;
    document.getElementById('contribGoalTitle').textContent = title;
    document.getElementById('addContributionModal').classList.add('show');
}

async function refreshForecast() {
    const btn = document.getElementById('refreshBtn');
    const msg = document.getElementById('refreshMsg');
    btn.disabled = true; btn.textContent = 'Retraining...';
    msg.style.display = 'block';
    msg.innerHTML = '<div class="alert" style="background:var(--lightgray);">Running the training script — this can take a few seconds...</div>';
    try {
        const res = await fetch('run_forecast.php', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            msg.innerHTML = '<div class="alert alert-success">Forecast refreshed! Reloading...</div>';
            setTimeout(() => location.reload(), 1200);
        } else {
            msg.innerHTML = '<div class="alert alert-error">Could not run it automatically (' + (data.reason || 'unknown reason') +
                '). Open a terminal in the project folder and run: <code>python ml/train_model.py</code></div>';
            btn.disabled = false; btn.textContent = '↻ Retrain now';
        }
    } catch (e) {
        msg.innerHTML = '<div class="alert alert-error">Could not reach the server. Run <code>python ml/train_model.py</code> manually instead.</div>';
        btn.disabled = false; btn.textContent = '↻ Retrain now';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
