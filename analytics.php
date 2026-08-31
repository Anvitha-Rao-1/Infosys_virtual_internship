<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Analytics';
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

$weekly_chart = [];
$cursor = new DateTime('-6 days');
for ($i = 0; $i < 7; $i++) {
    $d = $cursor->format('Y-m-d');
    $c = $heat_by_date[$d] ?? 0;
    $weekly_chart[] = ['label' => $cursor->format('D'), 'count' => $c, 'max' => max(1, $active_goal_count)];
    $cursor->modify('+1 day');
}

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

// ---- Category performance for insights ----
$stmt = $pdo->prepare("SELECT c.name, c.id, COUNT(g.id) goal_count,
    (SELECT COUNT(*) FROM goal_logs gl JOIN goals g2 ON g2.id=gl.goal_id WHERE g2.category_id=c.id AND g2.user_id=? AND gl.status='done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) as done_week
    FROM categories c LEFT JOIN goals g ON g.category_id=c.id AND g.user_id=? AND g.is_active=1
    GROUP BY c.id HAVING goal_count > 0");
$stmt->execute([$uid, $uid]);
$cat_perf = $stmt->fetchAll();
$best_cat = null; $worst_cat = null;
foreach ($cat_perf as $cp) {
    $rate = ($cp['goal_count'] * 7) > 0 ? $cp['done_week'] / ($cp['goal_count'] * 7) : 0;
    $cp['rate'] = $rate;
    if ($best_cat === null || $rate > $best_cat['rate']) $best_cat = $cp;
    if ($worst_cat === null || $rate < $worst_cat['rate']) $worst_cat = $cp;
}

// ---- Best/worst weekday ----
$stmt = $pdo->prepare("SELECT DAYNAME(gl.log_date) dname, DAYOFWEEK(gl.log_date) dnum, COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
    WHERE g.user_id=? AND gl.status='done' GROUP BY dnum, dname ORDER BY c DESC");
$stmt->execute([$uid]);
$weekday_perf = $stmt->fetchAll();
$best_day = $weekday_perf[0] ?? null;
$worst_day = !empty($weekday_perf) ? end($weekday_perf) : null;

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
?>

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

<div class="card" style="margin-bottom:24px;">
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
    <div class="bento-cell span-2">
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
    <div class="bento-cell span-2">
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

<div class="section-title"><h2>Performance insights</h2></div>
<div class="card">
    <?php if ($total_checkins === 0): ?>
        <div class="empty-state"><div class="em-ico">📊</div><p>Check in on a few goals to unlock insights here.</p></div>
    <?php else: ?>
        <?php if ($best_cat): ?>
        <div class="insight-card"><div class="ins-ico">🌟</div><p><strong><?= htmlspecialchars($best_cat['name']) ?></strong> is your strongest category this week at <strong><?= round($best_cat['rate']*100) ?>%</strong> completion.</p></div>
        <?php endif; ?>
        <?php if ($worst_cat && $worst_cat['id'] !== ($best_cat['id'] ?? null)): ?>
        <div class="insight-card"><div class="ins-ico">💡</div><p><strong><?= htmlspecialchars($worst_cat['name']) ?></strong> could use more attention — only <strong><?= round($worst_cat['rate']*100) ?>%</strong> completion this week.</p></div>
        <?php endif; ?>
        <?php if ($best_day): ?>
        <div class="insight-card"><div class="ins-ico">📅</div><p>You're most consistent on <strong><?= $best_day['dname'] ?></strong>s (<?= $best_day['c'] ?> check-ins logged).</p></div>
        <?php endif; ?>
        <div class="insight-card"><div class="ins-ico">📈</div><p>Your overall consistency score is <strong><?= $consistency ?>%</strong> across <?= $days_active ?> day<?= $days_active==1?'':'s' ?> of tracking.</p></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
