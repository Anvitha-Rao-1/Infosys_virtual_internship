<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Insights';
$active = 'insights';

// Insights — the ONE consolidated analysis hub (merges what used to be
// Productivity Analysis + Insights & Reports, plus the habit/productivity
// forecast that lived on Productivity Analysis — the Forecast *page* was
// 100% finance and stayed with Finance instead). Every chart here exists
// exactly once in the app; see README.md for the full duplicate → new
// home map. Rule-based insight *sentences* (best/worst category, mood
// correlation, streak-at-risk, trend %) now live solely on AI Coach —
// this hub shows the numbers and charts behind them, not the sentences.
function method_label_ins($m) { return forecast_method_label($m); }

// ============================================================
// Overview — the "how am I doing, broadly" tab
// ============================================================
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

$quote = daily_quote();

// vs-last-week trend deltas for the KPI row
$prod_hist_all = $habit_ready ? $habit['history']['productivity_score'] : [];
$prod_delta = (count($prod_hist_all) >= 2)
    ? trend_delta($prod_hist_all[count($prod_hist_all) - 1], $prod_hist_all[count($prod_hist_all) - 2])
    : null;
$h_score_prev = habit_score_prev_week($pdo, $uid);
$h_score_delta = trend_delta($h_score, $h_score_prev);
$yesterday_weekday_label = date('l', strtotime('-7 days'));
$tasks_prev = tasks_completed_on($pdo, $uid, date('Y-m-d', strtotime('-7 days')));
$tasks_delta = trend_delta_count($tasks['done'], $tasks_prev, $yesterday_weekday_label);

function trend_badge_ins($d) {
    if (!$d) return '';
    $colors = ['up' => 'var(--good-ink)', 'down' => 'var(--attention)', 'flat' => 'var(--ink-soft)'];
    $arrows = ['up' => '▲', 'down' => '▼', 'flat' => '●'];
    return '<div class="caption" style="color:' . $colors[$d['dir']] . '; font-weight:700; margin-top:6px;">' . $arrows[$d['dir']] . ' ' . htmlspecialchars($d['text']) . '</div>';
}

// ---- Heatmap (last 84 days) — the ONE copy in the app now ----
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
function ins_heat_color($count, $max) {
    if ($count == 0) return '#EFEFEF';
    $ratio = $count / $max;
    if ($ratio > 0.75) return '#8FAE8B';
    if ($ratio > 0.5) return '#B9F0D3';
    if ($ratio > 0.25) return '#DDF5E5';
    return '#EEF9F1';
}

// A plain-language weekly report, built entirely from the numbers already
// computed above — no ML, no external AI (same philosophy as AI Coach).
$report_lines = [];
$report_lines[] = "You've logged {$total_checkins} check-in" . ($total_checkins == 1 ? '' : 's') . " across {$active_goal_count} active goal" . ($active_goal_count == 1 ? '' : 's') . ", for a {$consistency}% overall consistency score since you started tracking.";
if ($best_cat) {
    $report_lines[] = "{$best_cat['name']} is your strongest category this week at {$best_cat['rate_pct']}% completion" . ($worst_cat && $worst_cat['id'] !== $best_cat['id'] ? ", while {$worst_cat['name']} is the one that could use more attention at {$worst_cat['rate_pct']}%." : '.');
}
if ($top_day) {
    $report_lines[] = "You're most consistent on {$top_day['dname']}s ({$top_day['c']} check-ins logged all-time).";
}
$current_wellness = wellness_score($pdo, $uid);
$wellness_trend = wellness_score_weekly($pdo, $uid, 8);
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

// ============================================================
// Trends tab
// ============================================================
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

// ============================================================
// Correlations tab
// ============================================================
$wellness_completion_points = wellness_completion_correlation($pdo, $uid, 8);
$focus_completion_points = focus_completion_daily($pdo, $uid, 30);

// ============================================================
// Forecast tab (habit/productivity — the finance forecast lives on
// the Finance page's own Forecast tab, not here)
// ============================================================
$focus_stats = focus_session_stats($pdo, $uid, 7);

if (!$habit || $habit['status'] !== 'ok') {
    $prod_trend_content = null;
    $prod_trend_empty_icon = '🔮';
    $prod_trend_empty_message = $habit ? htmlspecialchars($habit['message']) : 'No forecast yet. Run <code>python ml/train_model.py</code> once from the project folder, then refresh this page.';
} elseif (!$habit_ready) {
    $prod_trend_content = null;
    $prod_trend_empty_icon = '🔄';
    $prod_trend_empty_message = 'This forecast predates the Productivity Score update — go to Finance and click <strong>↻ Retrain now</strong> (or run <code>python ml/train_model.py</code>) to refresh it.';
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
        <table class="model-table" style="min-width:560px;">
            <tr><th>Metric</th><th>Linear Regression</th><th>Moving Average</th><th>ARIMA</th><th>Holt-Winters</th><th>Winner</th></tr>
            <tr>
                <td>Productivity score (MAE / RMSE)</td>
                <?php foreach (['linear_trend', 'moving_average', 'arima', 'holt_winters'] as $m): $sc = $habit['model']['productivity_scores'][$m] ?? null; ?>
                <td><?= $sc ? $sc['mae'] . ' / ' . $sc['rmse'] : '—' ?></td>
                <?php endforeach; ?>
                <td><?= method_label_ins($habit['model']['productivity_method']) ?></td>
            </tr>
            <tr>
                <td>Completion % (MAE / RMSE)</td>
                <?php foreach (['linear_trend', 'moving_average', 'arima', 'holt_winters'] as $m): $sc = $habit['model']['completion_scores'][$m] ?? null; ?>
                <td><?= $sc ? $sc['mae'] . ' / ' . $sc['rmse'] : '—' ?></td>
                <?php endforeach; ?>
                <td><?= method_label_ins($habit['model']['completion_method']) ?></td>
            </tr>
        </table>
        </div>
    </details>
    <?php $prod_trend_content = ob_get_clean();
    $prod_trend_empty_icon = null; $prod_trend_empty_message = null;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="coach-hero anim-in" style="background: linear-gradient(135deg, var(--lavender), var(--sky)); color:var(--ink);">
    <div class="coach-avatar" style="background: var(--ink); color: var(--lime);">📊</div>
    <div style="flex:1;">
        <h3 style="color:var(--ink);">Insights</h3>
        <p style="color:#3A3A45;">Live rule-based analytics plus a backtested trend forecast, all in one place. Looking for the money side? Head to <a href="finance.php" style="color:var(--ink); text-decoration:underline;">Finance</a>. Looking for plain-English recommendations? Head to <a href="coach.php" style="color:var(--ink); text-decoration:underline;">AI Coach</a>.</p>
    </div>
</div>

<div class="tab-group" id="insightsTabs">
<div class="seg-tabs">
    <button class="active" data-tab-target="overview">Overview</button>
    <button data-tab-target="trends">Trends</button>
    <button data-tab-target="correlations">Correlations</button>
    <button data-tab-target="forecast">Forecast</button>
</div>

<div class="tab-panel active" data-tab-panel="overview">
    <div class="card anim-in" style="margin-bottom:24px; background: linear-gradient(135deg, var(--lavender), var(--sky)); border:none;">
        <h4 style="text-transform:uppercase; font-size:12px; color:#3A3A45; letter-spacing:.06em; margin-bottom:12px;">📋 Your weekly report — <?= date('d M Y') ?></h4>
        <?php foreach ($report_lines as $line): ?>
        <p style="font-size:14px; font-weight:500; color:#2A2A33; line-height:1.6; margin-bottom:8px;"><?= htmlspecialchars($line) ?></p>
        <?php endforeach; ?>
    </div>

    <div class="bento-grid">
        <div class="bento-cell anim-in">
            <h4>Productivity score
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">0–100, computed weekly as 60% completion rate + 40% time invested.</span></span>
            </h4>
            <?php $prod_hist = $habit_ready ? $habit['history']['productivity_score'] : []; $cur_prod = !empty($prod_hist) ? end($prod_hist) : null; ?>
            <div class="stat-value"><?php if ($cur_prod !== null): ?><span class="count-up" data-target="<?= $cur_prod ?>" data-suffix="/100">0/100</span><?php else: ?>—<?php endif; ?></div>
            <?= trend_badge_ins($prod_delta) ?>
        </div>
        <div class="bento-cell anim-in">
            <h4>Habit score
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Your 4-week rolling completion rate — sustained consistency, independent of time invested.</span></span>
            </h4>
            <div class="stat-value"><?php if ($h_score !== null): ?><span class="count-up" data-target="<?= $h_score ?>" data-suffix="/100">0/100</span><?php else: ?>—<?php endif; ?></div>
            <?= trend_badge_ins($h_score_delta) ?>
        </div>
        <div class="bento-cell anim-in">
            <h4>Tasks completed today</h4>
            <div class="stat-value"><?= $tasks['done'] ?>/<?= $tasks['total'] ?></div>
            <?= trend_badge_ins($tasks_delta) ?>
        </div>
        <div class="bento-cell anim-in">
            <h4>Current streak</h4>
            <div class="stat-value"><span class="count-up" data-target="<?= $best_streak['days'] ?>" data-suffix=" days">0 days</span></div>
            <?php if ($best_streak['title']): ?><div class="caption" style="margin-top:4px;"><?= htmlspecialchars($best_streak['title']) ?></div><?php endif; ?>
            <?php if ($best_streak_ever > $best_streak['days']): ?><div class="caption" style="margin-top:4px;">🏆 Personal best: <?= $best_streak_ever ?>d</div><?php endif; ?>
        </div>
    </div>

    <div class="bento-grid">
        <div class="bento-cell span-4 anim-in">
            <h4>Weekly goal progress
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">This week's check-ins completed vs. possible, across all active goals, against a 90% target (not 100% — one missed day shouldn't read as failure).</span></span>
            </h4>
            <div style="display:flex; align-items:center; gap:16px; margin-top:8px;">
                <div class="tracker-bar" style="margin:0; height:16px;"><span class="grow-in" style="width:<?= $goal_progress['pct'] ?>%; background: var(--lime-deep);"></span></div>
                <strong style="white-space:nowrap;"><?= $goal_progress['pct'] ?>% <span style="color:var(--ink-soft); font-weight:600;">/ <?= $goal_progress['target'] ?>% goal</span></strong>
            </div>
        </div>
    </div>

    <div class="bento-grid">
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
        <div class="bento-cell span-2 anim-in" style="background: linear-gradient(135deg, var(--lavender), var(--white)); display:flex; flex-direction:column; justify-content:center;">
            <h4 style="margin-bottom:8px;">💬 Today's quote</h4>
            <p style="font-size:14.5px; font-style:italic; line-height:1.5; margin:0;">"<?= htmlspecialchars($quote['text']) ?>"</p>
            <p style="font-size:12.5px; color:var(--ink-soft); margin-top:8px; margin-bottom:0;">— <?= htmlspecialchars($quote['author']) ?></p>
        </div>
    </div>

    <div class="bento-grid">
    <?php
    ob_start(); ?>
    <div class="heatmap">
        <?php foreach ($heat_days as $d): $c = $heat_by_date[$d] ?? 0; ?>
        <div class="hm-cell" style="background:<?= ins_heat_color($c, $max_heat) ?>" title="<?= $d ?>: <?= $c ?> check-in<?= $c==1?'':'s' ?>"></div>
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

    <p style="text-align:center; margin-top:4px;"><a href="coach.php" class="btn btn-ghost btn-sm">✨ See your full AI Coach summary →</a></p>
</div>

<div class="tab-panel" data-tab-panel="trends">
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
    $wellness_trend_content = (count($wellness_trend['labels']) < 2) ? null : svg_line_chart($wellness_trend['labels'], [
        'Wellness score' => ['actual' => $wellness_trend['scores'], 'forecast' => [], 'color' => '#EE8AD1'],
    ], 640, 220);
    echo chart_card([
        'title' => 'Mood &amp; wellness trend — last 8 weeks',
        'info' => 'Your weekly wellness score (mood + sleep + stress blend, same formula as the Mood Tracker) over time.',
        'span' => 2,
        'content' => $wellness_trend_content,
        'chart_width' => 640,
        'empty_message' => 'Check in on the Mood Tracker for a couple of weeks to see this trend.',
    ]);
    ?>
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

    <div class="bento-grid">
    <?= chart_card([
        'title' => 'Weekly rhythm — productivity by day',
        'info' => 'Average completion score for each weekday over the last 8 weeks, all seven days at once.',
        'span' => 4,
        'content' => empty($weekday_scores) ? null : svg_radar_chart($weekday_order, [
            'Avg. score' => ['values' => $radar_week_values, 'color' => '#9391F5'],
        ], 100, 320),
        'empty_message' => 'Check in on a few goals to see this here.',
    ]) ?>
    </div>
</div>

<div class="tab-panel" data-tab-panel="correlations">
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
    <div class="bento-grid">
    <?= chart_card([
        'title' => 'Focus time vs. completion — last 30 days',
        'info' => 'One dot per day: minutes spent in a Focus Session against that day\'s habit check-in completion %, with a fitted trend line.',
        'span' => 4,
        'content' => count($focus_completion_points) < 3 ? null : svg_scatter_chart($focus_completion_points, 'Focus minutes', 'Completion %', '#9FB6F5', 1100, 220),
        'chart_width' => 1100,
        'empty_message' => 'Log a few Focus Sessions to see this correlation.',
    ]) ?>
    </div>
</div>

<div class="tab-panel" data-tab-panel="forecast">
    <div class="bento-grid">
    <?= chart_card([
        'title' => 'Productivity trend — actual &amp; forecast',
        'info' => 'Backtested across four candidate models (Linear Regression, Moving Average, ARIMA, Holt-Winters).',
        'span' => 4,
        'note' => $prod_trend_content ? 'Weeks run Monday–Sunday. Dashed points are the next two weeks, projected. The shaded band is a 95% confidence interval from the winning model\'s own backtest error.' : null,
        'content' => $prod_trend_content,
        'chart_width' => $prod_trend_content ? 640 : null,
        'empty_icon' => $prod_trend_empty_icon ?? '🔮',
        'empty_message' => $prod_trend_empty_message ?? '',
    ]) ?>
    </div>
    <div class="bento-grid">
        <div class="bento-cell span-4 anim-in">
            <h4>Focus sessions — last 7 days</h4>
            <div class="tracker-row"><span>Sessions</span><strong><?= $focus_stats['sessions'] ?></strong></div>
            <div class="tracker-row"><span>Avg. focus time</span><strong><?= $focus_stats['avg_minutes'] ?> min</strong></div>
            <div class="tracker-row"><span>Success rate</span><strong><?= $focus_stats['success_rate'] ?>%</strong></div>
            <a href="focus.php" class="btn btn-ghost btn-sm btn-block" style="margin-top:12px;">Go to Focus Sessions →</a>
        </div>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
