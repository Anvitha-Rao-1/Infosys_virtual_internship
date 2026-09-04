<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Insights & Reports';
$active = 'analytics';

// ---- Heatmap: last 84 days, count of check-ins per day ----
$stmt = $pdo->prepare("SELECT gl.log_date, COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
    WHERE g.user_id=? AND gl.status='done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 83 DAY)
    GROUP BY gl.log_date");
$stmt->execute([$uid]);
$heat_by_date = [];
foreach ($stmt->fetchAll() as $r) $heat_by_date[$r['log_date']] = (int)$r['c'];

$heat_days = [];
$cursor = new DateTime('-83 days');
for ($i = 0; $i < 84; $i++) { $heat_days[] = $cursor->format('Y-m-d'); $cursor->modify('+1 day'); }

// ---- Weekly chart: last 7 days, completions vs active goals ----
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
$stmt->execute([$uid]);
$active_goal_count = (int)$stmt->fetch()['c'];

$weekly_chart = weekly_checkin_bars($pdo, $uid);

// ---- Monthly chart: last ~5 weeks, completion % per week ----
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

// ---- Consistency / overall stats ----
$total_checkins = total_checkins($pdo, $uid);
$stmt = $pdo->prepare("SELECT MIN(created_at) mn FROM goals WHERE user_id=?");
$stmt->execute([$uid]);
$first_goal_date = $stmt->fetch()['mn'];
$days_active = $first_goal_date ? max(1, (new DateTime())->diff(new DateTime($first_goal_date))->days + 1) : 1;
$consistency = $active_goal_count > 0 ? min(100, round(($total_checkins / ($active_goal_count * $days_active)) * 100)) : 0;

// ---- Category performance — full comparison, not just best/worst ----
$cat_perf = category_completion_this_week($pdo, $uid);
$best_cat = $cat_perf[0] ?? null;
$worst_cat = !empty($cat_perf) ? end($cat_perf) : null;

// ---- Best/worst weekday ----
$stmt = $pdo->prepare("SELECT DAYNAME(gl.log_date) dname, DAYOFWEEK(gl.log_date) dnum, COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
    WHERE g.user_id=? AND gl.status='done' GROUP BY dnum, dname ORDER BY c DESC");
$stmt->execute([$uid]);
$weekday_perf = $stmt->fetchAll();
$best_day = $weekday_perf[0] ?? null;
$worst_day = !empty($weekday_perf) ? end($weekday_perf) : null;

// ---- Mood & wellness trend (live, last 8 weeks) ----
$wellness_trend = wellness_score_weekly($pdo, $uid, 8);
$current_wellness = wellness_score($pdo, $uid);

// ---- Finance snapshot (live from transactions, no ML dependency) ----
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$stmt = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) total FROM transactions WHERE user_id=? AND txn_date BETWEEN ? AND ? GROUP BY type");
$stmt->execute([$uid, $month_start, $month_end]);
$fin_totals = ['income' => 0.0, 'expense' => 0.0];
foreach ($stmt->fetchAll() as $r) $fin_totals[$r['type']] = (float)$r['total'];
$fin_profit = $fin_totals['income'] - $fin_totals['expense'];
$fin_history = monthly_transaction_totals($pdo, $uid, 6);
$has_finance_data = !empty($fin_history['months']);

require_once __DIR__ . '/includes/header.php';

$max_heat = max(1, max($heat_by_date ?: [0]));
function heat_color($count, $max) {
    if ($count == 0) return '#EFEFEF';
    $ratio = $count / $max;
    if ($ratio > 0.75) return '#8FAE8B';
    if ($ratio > 0.5) return '#B9F0D3';
    if ($ratio > 0.25) return '#DDF5E5';
    return '#EEF9F1';
}

// A plain-language weekly report, built entirely from the numbers already
// computed above — no ML, no external AI, just sentences assembled from
// real query results (the same "explainable, not a black box" philosophy
// as coach.php and the forecast pages).
$report_lines = [];
$report_lines[] = "You've logged {$total_checkins} check-in" . ($total_checkins == 1 ? '' : 's') . " across {$active_goal_count} active goal" . ($active_goal_count == 1 ? '' : 's') . ", for a {$consistency}% overall consistency score since you started tracking.";
if ($best_cat) {
    $report_lines[] = "{$best_cat['name']} is your strongest category this week at {$best_cat['rate_pct']}% completion" . ($worst_cat && $worst_cat['id'] !== $best_cat['id'] ? ", while {$worst_cat['name']} is the one that could use more attention at {$worst_cat['rate_pct']}%." : '.');
}
if ($best_day) {
    $report_lines[] = "You're most consistent on {$best_day['dname']}s ({$best_day['c']} check-ins logged all-time).";
}
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
if ($fin_totals['income'] > 0 || $fin_totals['expense'] > 0) {
    $margin_word = $fin_profit >= 0 ? 'saved' : 'overspent by';
    $report_lines[] = "Financially, this month you've brought in ₹" . number_format($fin_totals['income'], 0) . " and spent ₹" . number_format($fin_totals['expense'], 0) . " — you've {$margin_word} ₹" . number_format(abs($fin_profit), 0) . " so far.";
} else {
    $report_lines[] = "Log a transaction on the Finance page to bring your spending into this report.";
}
?>

<style>
@media print {
    .sidebar, .topbar, .btn, .info-dot, .section-title .btn { display:none !important; }
    .main-panel { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; }
}
</style>

<div class="section-title">
    <h2>Insights &amp; Reports</h2>
    <button class="btn btn-ghost btn-sm" onclick="window.print()">🖨️ Print report</button>
</div>

<div class="card anim-in" style="margin-bottom:24px; background: linear-gradient(135deg, var(--lavender), var(--sky)); border:none;">
    <h4 style="text-transform:uppercase; font-size:12px; color:#3A3A45; letter-spacing:.06em; margin-bottom:12px;">📋 Your weekly report — <?= date('d M Y') ?></h4>
    <?php foreach ($report_lines as $line): ?>
    <p style="font-size:14px; font-weight:500; color:#2A2A33; line-height:1.6; margin-bottom:8px;"><?= htmlspecialchars($line) ?></p>
    <?php endforeach; ?>
</div>

<div class="bento-grid">
    <div class="bento-cell">
        <h4>Total check-ins</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:38px;"><?= $total_checkins ?></div>
    </div>
    <div class="bento-cell">
        <h4>Consistency</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:38px;"><?= $consistency ?>%</div>
    </div>
    <div class="bento-cell">
        <h4>Active goals</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:38px;"><?= $active_goal_count ?></div>
    </div>
    <div class="bento-cell">
        <h4>Best day</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:22px; margin-top:8px;"><?= $best_day['dname'] ?? '—' ?></div>
    </div>
</div>

<div class="card anim-in" style="margin-bottom:24px;">
    <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:14px;">Habit heatmap · last 12 weeks</h4>
    <div class="heatmap">
        <?php foreach ($heat_days as $d): $c = $heat_by_date[$d] ?? 0; ?>
        <div class="hm-cell" style="background:<?= heat_color($c, $max_heat) ?>" title="<?= $d ?>: <?= $c ?> check-in<?= $c==1?'':'s' ?>"></div>
        <?php endforeach; ?>
    </div>
    <div class="heatmap-legend">
        Less <div class="hm-cell" style="background:#EFEFEF"></div><div class="hm-cell" style="background:#EEF9F1"></div><div class="hm-cell" style="background:#DDF5E5"></div><div class="hm-cell" style="background:#B9F0D3"></div><div class="hm-cell" style="background:#8FAE8B"></div> More
    </div>
</div>

<div class="bento-grid">
    <div class="bento-cell span-2 anim-in">
        <h4>This week</h4>
        <div class="chart-bars">
            <?php foreach ($weekly_chart as $w): $h = $w['max'] > 0 ? ($w['count']/$w['max'])*100 : 0; ?>
            <div class="cb-col">
                <div class="cb-bar" style="height:<?= max(6,$h) ?>%; background:var(--lavender);"></div>
                <div class="cb-label"><?= $w['label'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="bento-cell span-2 anim-in">
        <h4>Last 5 weeks · completion %</h4>
        <div class="chart-bars">
            <?php foreach ($monthly_chart as $m): ?>
            <div class="cb-col">
                <div class="cb-bar" style="height:<?= max(6,$m['pct']) ?>%; background:var(--pink-deep);"></div>
                <div class="cb-label"><?= $m['label'] ?> · <?= $m['pct'] ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="bento-grid">
    <div class="bento-cell span-2 anim-in">
        <h4>Category comparison — this week
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Completion % this week for every category with at least one active goal, side by side.</span></span>
        </h4>
        <?php if (empty($cat_perf)): ?>
            <div class="empty-state" style="padding:24px 10px;"><p style="font-size:13px;">Add a goal in any category to see this comparison.</p></div>
        <?php else: ?>
        <div class="chart-bars">
            <?php foreach ($cat_perf as $cp): ?>
            <div class="cb-col">
                <div class="cb-bar" style="height:<?= max(6,$cp['rate_pct']) ?>%; background:<?= htmlspecialchars($cp['color']) ?>;"></div>
                <div class="cb-label"><?= htmlspecialchars(short_label($cp['name'], 11)) ?> · <?= $cp['rate_pct'] ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="bento-cell span-2 anim-in">
        <h4>Mood &amp; wellness trend — last 8 weeks
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Your weekly wellness score (mood + sleep + stress blend, same formula as the Mood Tracker) over time — live, computed fresh, no forecasting.</span></span>
        </h4>
        <?php if (count($wellness_trend['labels']) < 2): ?>
            <div class="empty-state" style="padding:24px 10px;"><p style="font-size:13px;">Check in on the Mood Tracker for a couple of weeks to see this trend.</p></div>
        <?php else: ?>
        <?= svg_line_chart($wellness_trend['labels'], [
            'Wellness score' => ['actual' => $wellness_trend['scores'], 'forecast' => [], 'color' => '#EE8AD1'],
        ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="section-title"><h2>Financial snapshot</h2></div>
<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Income — this month</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;"><span class="count-up" data-prefix="₹" data-target="<?= $fin_totals['income'] ?>">₹0</span></div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Expenses — this month</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;"><span class="count-up" data-prefix="₹" data-target="<?= $fin_totals['expense'] ?>">₹0</span></div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Net — this month</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px; color:<?= $fin_profit >= 0 ? 'var(--ink)' : '#B8447A' ?>"><span class="count-up" data-prefix="₹" data-target="<?= $fin_profit ?>">₹0</span></div>
    </div>
    <div class="bento-cell anim-in" style="justify-content:center; gap:8px;">
        <h4 style="margin-bottom:4px;">Go deeper</h4>
        <a href="finance.php" class="btn btn-ghost btn-sm btn-block">Finance page</a>
        <a href="forecast.php" class="btn btn-ghost btn-sm btn-block">Forecast page</a>
    </div>
</div>
<div class="bento-grid">
    <div class="bento-cell span-4 anim-in">
        <h4>Income vs. expenses — last 6 months</h4>
        <?php if (!$has_finance_data): ?>
            <div class="empty-state" style="padding:24px 10px;"><p style="font-size:13px;">Log a few transactions on the Finance page to see this chart.</p></div>
        <?php else: ?>
        <?= svg_line_chart($fin_history['months'], [
            'Income' => ['actual' => $fin_history['income'], 'forecast' => [], 'color' => '#5C8AE6'],
            'Expense' => ['actual' => $fin_history['expense'], 'forecast' => [], 'color' => '#EE8AD1'],
        ], 1100, 200) ?>
        <?php endif; ?>
    </div>
</div>

<div class="section-title"><h2>Performance insights</h2></div>
<div class="card">
    <?php if ($total_checkins === 0): ?>
        <div class="empty-state"><div class="em-ico">📊</div><p>Check in on a few goals to unlock insights here.</p></div>
    <?php else: ?>
        <?php if ($best_cat): ?>
        <div class="insight-card"><div class="ins-ico">🌟</div><p><strong><?= htmlspecialchars($best_cat['name']) ?></strong> is your strongest category this week at <strong><?= $best_cat['rate_pct'] ?>%</strong> completion.</p></div>
        <?php endif; ?>
        <?php if ($worst_cat && $worst_cat['id'] !== ($best_cat['id'] ?? null)): ?>
        <div class="insight-card"><div class="ins-ico">💡</div><p><strong><?= htmlspecialchars($worst_cat['name']) ?></strong> could use more attention — only <strong><?= $worst_cat['rate_pct'] ?>%</strong> completion this week.</p></div>
        <?php endif; ?>
        <?php if ($best_day): ?>
        <div class="insight-card"><div class="ins-ico">📅</div><p>You're most consistent on <strong><?= $best_day['dname'] ?></strong>s (<?= $best_day['c'] ?> check-ins logged).</p></div>
        <?php endif; ?>
        <div class="insight-card"><div class="ins-ico">📈</div><p>Your overall consistency score is <strong><?= $consistency ?>%</strong> across <?= $days_active ?> day<?= $days_active==1?'':'s' ?> of tracking.</p></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
