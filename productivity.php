<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Productivity Analysis';
$active = 'productivity';

function method_label_pa($m) { return $m ? ucwords(str_replace('_', ' ', $m)) : '—'; }

// Renders a small "▲ +12% vs last week" style badge from a trend_delta()/
// trend_delta_count() result. Returns '' (nothing) when $d is null, i.e.
// there's no previous-period data to compare against yet.
function trend_badge($d) {
    if (!$d) return '';
    $colors = ['up' => '#2F5233', 'down' => '#D6577A', 'flat' => 'var(--ink-soft)'];
    $arrows = ['up' => '▲', 'down' => '▼', 'flat' => '●'];
    $color = $colors[$d['dir']];
    $arrow = $arrows[$d['dir']];
    return '<div style="font-size:11.5px; font-weight:700; color:' . $color . '; margin-top:6px;">' . $arrow . ' ' . htmlspecialchars($d['text']) . '</div>';
}

// ML-backed trend forecast (written by ml/train_model.py)
$habit = get_forecast($pdo, $uid, 'habit');
$habit_ready = $habit && $habit['status'] === 'ok' && isset($habit['history']['productivity_score']);

// Live rule-based analytics — always fresh, no retraining needed
$h_score = habit_score($pdo, $uid);
$best_streak = overall_best_streak($pdo, $uid);
$tasks = tasks_completed_today($pdo, $uid);
$goal_progress = weekly_goal_progress($pdo, $uid);
$focus_stats = focus_session_stats($pdo, $uid, 7);
$habit_overview = active_goals_with_progress($pdo, $uid, 8);
$time_alloc = category_time_allocation($pdo, $uid, 7);
[$best_cat, $worst_cat] = best_worst_category_week($pdo, $uid);
$top_day = best_weekday($pdo, $uid);

// ---- "vs last week" trend deltas for the KPI row ----
$prod_hist_all = $habit_ready ? $habit['history']['productivity_score'] : [];
$prod_delta = (count($prod_hist_all) >= 2)
    ? trend_delta($prod_hist_all[count($prod_hist_all) - 1], $prod_hist_all[count($prod_hist_all) - 2])
    : null;

$h_score_prev = habit_score_prev_week($pdo, $uid);
$h_score_delta = trend_delta($h_score, $h_score_prev);

$yesterday_weekday_label = date('l', strtotime('-7 days'));
$tasks_prev = tasks_completed_on($pdo, $uid, date('Y-m-d', strtotime('-7 days')));
$tasks_delta = trend_delta_count($tasks['done'], $tasks_prev, $yesterday_weekday_label);

$focus_prev_minutes = focus_minutes_prev_week($pdo, $uid);
$focus_delta = trend_delta($focus_stats['total_minutes'], $focus_prev_minutes);

$best_streak_ever = all_time_best_streak($pdo, $uid);

// ---- Top / Least Productive Day (comparable 0-100 score, not just a raw count) ----
$weekday_scores = weekday_productivity_scores($pdo, $uid, 8);
$top_productive_day = $weekday_scores[0] ?? null;
$least_productive_day = (count($weekday_scores) > 1) ? end($weekday_scores) : null;

$quote = daily_quote();

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

// ---- Activity heatmap (last 84 days) ----
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
function pa_heat_color($count, $max) {
    if ($count == 0) return '#EFEFEF';
    $ratio = $count / $max;
    if ($ratio > 0.75) return '#8FAE8B';
    if ($ratio > 0.5) return '#B9F0D3';
    if ($ratio > 0.25) return '#DDF5E5';
    return '#EEF9F1';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="coach-hero anim-in" style="background: linear-gradient(135deg, var(--lavender), var(--sky)); color:var(--ink);">
    <div class="coach-avatar" style="background: var(--ink); color: var(--lime);">📈</div>
    <div style="flex:1;">
        <h3 style="color:var(--ink);">Productivity & Habit Analysis</h3>
        <p style="color:#3A3A45;">Live rule-based analytics plus a backtested trend forecast — see <a href="forecast.php" style="color:var(--ink); text-decoration:underline;">Forecast</a> for the financial side.</p>
    </div>
</div>

<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Productivity score
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">0–100, computed weekly as 60% completion rate + 40% time invested (real Focus Session minutes when you have them, otherwise estimated from check-ins).</span></span>
        </h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;">
            <?php
            $prod_hist = $habit_ready ? $habit['history']['productivity_score'] : [];
            $cur_prod = !empty($prod_hist) ? end($prod_hist) : null;
            ?>
            <?php if ($cur_prod !== null): ?><span class="count-up" data-target="<?= $cur_prod ?>" data-suffix="/100">0/100</span><?php else: ?>—<?php endif; ?>
        </div>
        <?= trend_badge($prod_delta) ?>
    </div>
    <div class="bento-cell anim-in">
        <h4>Habit score
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Your 4-week rolling completion rate — sustained consistency, independent of time invested.</span></span>
        </h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;">
            <?php if ($h_score !== null): ?><span class="count-up" data-target="<?= $h_score ?>" data-suffix="/100">0/100</span><?php else: ?>—<?php endif; ?>
        </div>
        <?= trend_badge($h_score_delta) ?>
    </div>
    <div class="bento-cell anim-in">
        <h4>Tasks completed
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Habit check-ins done today, out of your active goals.</span></span>
        </h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;"><?= $tasks['done'] ?>/<?= $tasks['total'] ?></div>
        <?= trend_badge($tasks_delta) ?>
    </div>
    <div class="bento-cell anim-in">
        <h4>Current streak</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;"><span class="count-up" data-target="<?= $best_streak['days'] ?>" data-suffix=" days">0 days</span></div>
        <?php if ($best_streak['title']): ?><div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;"><?= htmlspecialchars($best_streak['title']) ?></div><?php endif; ?>
        <?php if ($best_streak_ever > $best_streak['days']): ?><div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;">🏆 Personal best: <?= $best_streak_ever ?>d</div><?php endif; ?>
    </div>
</div>

<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Focus time this week</h4>
        <?php $fh = intdiv($focus_stats['total_minutes'], 60); $fm = $focus_stats['total_minutes'] % 60; ?>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;"><?= $fh ?>h <?= $fm ?>m</div>
        <div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;"><?= $focus_stats['sessions'] ?> session<?= $focus_stats['sessions'] == 1 ? '' : 's' ?> · <a href="focus.php" style="color:var(--lavender-deep); font-weight:700;">start one →</a></div>
        <?= trend_badge($focus_delta) ?>
    </div>
    <div class="bento-cell span-3 anim-in">
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
    <div class="bento-cell anim-in" style="background: linear-gradient(135deg, #DDF5E5, var(--white));">
        <h4>🏆 Top productive day
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Average completion score for this weekday over the last 8 weeks — 100 means every active goal was checked in every time this day came around.</span></span>
        </h4>
        <?php if ($top_productive_day): ?>
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:22px;"><?= htmlspecialchars($top_productive_day['dname']) ?></div>
            <div style="font-size:13px; color:var(--ink-soft); margin-top:2px;"><?= $top_productive_day['score'] ?>/100 average score</div>
        <?php else: ?>
            <p style="font-size:13px; color:var(--ink-soft);">Check in on a few goals to see this here.</p>
        <?php endif; ?>
    </div>
    <div class="bento-cell anim-in" style="background: linear-gradient(135deg, #FBE7EC, var(--white));">
        <h4>🌱 Least productive day
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Your lowest average completion score by weekday, over the last 8 weeks — a good candidate for lighter goals or an extra reminder.</span></span>
        </h4>
        <?php if ($least_productive_day): ?>
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:22px;"><?= htmlspecialchars($least_productive_day['dname']) ?></div>
            <div style="font-size:13px; color:var(--ink-soft); margin-top:2px;"><?= $least_productive_day['score'] ?>/100 average score</div>
        <?php else: ?>
            <p style="font-size:13px; color:var(--ink-soft);">Needs a couple more weeks of check-ins across different days.</p>
        <?php endif; ?>
    </div>
    <div class="bento-cell span-2 anim-in" style="background: linear-gradient(135deg, var(--lavender), var(--white)); display:flex; flex-direction:column; justify-content:center;">
        <h4 style="margin-bottom:8px;">💬 Today's quote</h4>
        <p style="font-size:14.5px; font-style:italic; line-height:1.5; margin:0;">"<?= htmlspecialchars($quote['text']) ?>"</p>
        <p style="font-size:12.5px; color:var(--ink-soft); margin-top:8px; margin-bottom:0;">— <?= htmlspecialchars($quote['author']) ?></p>
    </div>
</div>

<?php if (!$habit || $habit['status'] !== 'ok'): ?>
    <div class="card empty-state" style="margin-bottom:24px;">
        <div class="em-ico">🔮</div>
        <p><?= $habit ? htmlspecialchars($habit['message']) : 'No forecast yet. Run <code>python ml/train_model.py</code> once from the project folder, then refresh this page.' ?></p>
    </div>
<?php elseif (!$habit_ready): ?>
    <div class="card empty-state" style="margin-bottom:24px;">
        <div class="em-ico">🔄</div>
        <p>This forecast predates the Productivity Score update — go to <a href="forecast.php">Forecast</a> and click <strong>↻ Retrain now</strong> (or run <code>python ml/train_model.py</code>) to refresh it.</p>
    </div>
<?php else: ?>
<div class="card anim-in" style="margin-bottom:24px;">
    <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:4px;">
        Productivity trend — actual &amp; forecast
        <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Backtested Linear Regression vs. Moving Average on your weekly history — whichever predicted recent weeks more accurately draws the dashed line.</span></span>
    </h4>
    <p class="chart-note">Weeks run Monday–Sunday. Dashed points are the next two weeks, projected — not logged yet.</p>
    <div class="chart-draw">
    <?php
    $wlabels = [];
    $n_actual_weeks = count($habit['history']['weeks']);
    for ($i = 0; $i < $n_actual_weeks; $i++) $wlabels[] = 'Wk ' . ($i + 1);
    $n_fc_weeks = count($habit['forecast']['completion_pct']);
    for ($i = 0; $i < $n_fc_weeks; $i++) $wlabels[] = '+' . ($i + 1);
    echo svg_line_chart($wlabels, [
        'Productivity score' => ['actual' => $habit['history']['productivity_score'], 'forecast' => $habit['forecast']['productivity_score'], 'color' => '#9391F5'],
        'Completion %' => ['actual' => $habit['history']['completion_pct'], 'forecast' => $habit['forecast']['completion_pct'], 'color' => '#EE8AD1'],
    ]);
    ?>
    </div>
    <details class="model-details">
        <summary>Why these numbers? (model performance)</summary>
        <table class="model-table">
            <tr><th>Metric</th><th>Linear Regression</th><th>Moving Average</th><th>Winner</th></tr>
            <tr>
                <td>Productivity score (MAE / RMSE)</td>
                <td><?= isset($habit['model']['productivity_scores']['linear_trend']) ? $habit['model']['productivity_scores']['linear_trend']['mae'] . ' / ' . $habit['model']['productivity_scores']['linear_trend']['rmse'] : '—' ?></td>
                <td><?= isset($habit['model']['productivity_scores']['moving_average']) ? $habit['model']['productivity_scores']['moving_average']['mae'] . ' / ' . $habit['model']['productivity_scores']['moving_average']['rmse'] : '—' ?></td>
                <td><?= method_label_pa($habit['model']['productivity_method']) ?></td>
            </tr>
            <tr>
                <td>Completion % (MAE / RMSE)</td>
                <td><?= isset($habit['model']['completion_scores']['linear_trend']) ? $habit['model']['completion_scores']['linear_trend']['mae'] . ' / ' . $habit['model']['completion_scores']['linear_trend']['rmse'] : '—' ?></td>
                <td><?= isset($habit['model']['completion_scores']['moving_average']) ? $habit['model']['completion_scores']['moving_average']['mae'] . ' / ' . $habit['model']['completion_scores']['moving_average']['rmse'] : '—' ?></td>
                <td><?= method_label_pa($habit['model']['completion_method']) ?></td>
            </tr>
        </table>
    </details>
</div>
<?php endif; ?>

<div class="bento-grid">
    <div class="bento-cell span-2 anim-in">
        <h4>Time allocation by category
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Minutes invested per category this week — real Focus Session time when a session is linked to a goal, plus estimated minutes (session length × check-ins) for the rest.</span></span>
        </h4>
        <?php if (empty($donut_segments)): ?>
            <div class="empty-state" style="padding:30px 10px;"><p style="font-size:13px;">Check in on a goal or log a focus session this week to see this here.</p></div>
        <?php else: ?>
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
        <?php endif; ?>
    </div>
    <div class="bento-cell span-2 anim-in">
        <h4>Insights summary</h4>
        <?php $shown_insight = false; ?>
        <?php if ($habit_ready): foreach ($habit['insights'] as $ins): $shown_insight = true; ?>
        <div class="insight-card"><div class="ins-ico">📈</div><p><?= htmlspecialchars($ins) ?></p></div>
        <?php endforeach; endif; ?>
        <?php if ($best_cat): $shown_insight = true; ?>
        <div class="insight-card"><div class="ins-ico">🌟</div><p><strong><?= htmlspecialchars($best_cat['name']) ?></strong> is your strongest category this week at <strong><?= round($best_cat['rate'] * 100) ?>%</strong> completion.</p></div>
        <?php endif; ?>
        <?php if ($worst_cat && (!$best_cat || $worst_cat['id'] !== $best_cat['id'])): $shown_insight = true; ?>
        <div class="insight-card"><div class="ins-ico">💡</div><p><strong><?= htmlspecialchars($worst_cat['name']) ?></strong> could use a little more attention — <strong><?= round($worst_cat['rate'] * 100) ?>%</strong> completion this week.</p></div>
        <?php endif; ?>
        <?php if ($top_day): $shown_insight = true; ?>
        <div class="insight-card"><div class="ins-ico">📅</div><p>You're most consistent on <strong><?= $top_day['dname'] ?></strong>s (<?= $top_day['c'] ?> check-ins logged all-time).</p></div>
        <?php endif; ?>
        <?php if (!$shown_insight): ?>
        <div class="empty-state" style="padding:20px 10px;"><p style="font-size:13px;">Check in on a few goals to unlock insights here.</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card anim-in" style="margin-bottom:24px;">
    <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:14px;">Activity heatmap · last 12 weeks</h4>
    <div class="heatmap">
        <?php foreach ($heat_days as $d): $c = $heat_by_date[$d] ?? 0; ?>
        <div class="hm-cell" style="background:<?= pa_heat_color($c, $max_heat) ?>" title="<?= $d ?>: <?= $c ?> check-in<?= $c==1?'':'s' ?>"></div>
        <?php endforeach; ?>
    </div>
    <div class="heatmap-legend">
        Less <div class="hm-cell" style="background:#EFEFEF"></div><div class="hm-cell" style="background:#EEF9F1"></div><div class="hm-cell" style="background:#DDF5E5"></div><div class="hm-cell" style="background:#B9F0D3"></div><div class="hm-cell" style="background:#8FAE8B"></div> More
    </div>
</div>

<div class="bento-grid">
    <div class="bento-cell span-2 anim-in">
        <h4>Focus sessions</h4>
        <div class="tracker-row"><span>Sessions this week</span><strong><?= $focus_stats['sessions'] ?></strong></div>
        <div class="tracker-row"><span>Avg. focus time</span><strong><?= $focus_stats['avg_minutes'] ?> min</strong></div>
        <div class="tracker-row"><span>Success rate</span><strong><?= $focus_stats['success_rate'] ?>%</strong></div>
        <a href="focus.php" class="btn btn-ghost btn-sm btn-block" style="margin-top:12px;">Go to Focus Sessions →</a>
    </div>
    <div class="bento-cell span-2 anim-in">
        <h4>Habit overview</h4>
        <?php if (empty($habit_overview)): ?>
            <div class="empty-state" style="padding:20px 10px;"><p style="font-size:13px;">No active goals yet.</p></div>
        <?php else: foreach (array_slice($habit_overview, 0, 4) as $g): ?>
            <div class="habit-ov-row">
                <div class="ho-name"><?= htmlspecialchars($g['title']) ?><span><?= htmlspecialchars($g['category']) ?></span></div>
                <div class="tracker-bar"><span class="grow-in" style="width:<?= $g['pct'] ?>%; background: var(--sky-deep);"></span></div>
                <div class="ho-streak">🔥 <?= $g['streak'] ?>d</div>
            </div>
        <?php endforeach; endif; ?>
        <a href="habit_tracker.php" class="btn btn-ghost btn-sm btn-block" style="margin-top:12px;">View full Habit Tracker →</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
