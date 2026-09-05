<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$page_title = 'Dashboard';
$active = 'dashboard';

$uid = $user['id'];
$today = date('Y-m-d');

// Dashboard — the "arrive here daily" home. Today's check-in list, one
// hero progress ring, one trend chart, and a short "needs attention" list
// — everything else (KPI breakdowns, check-in history, time allocation,
// finance) now lives on Insights / Finance / Habits, one click away.

$total_goals = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
$total_goals->execute([$uid]);
$total_goals = $total_goals->fetch()['c'];

$done_today = $pdo->prepare("SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id WHERE g.user_id=? AND gl.log_date=? AND gl.status='done'");
$done_today->execute([$uid, $today]);
$done_today = $done_today->fetch()['c'];

evaluate_achievements($pdo, $uid);

// ---- Today's check-in list + "needs attention" (streaks at risk) ----
$pending_today = goals_pending_today($pdo, $uid);
$at_risk = streaks_at_risk($pdo, $uid, $pending_today);
$at_risk_goal_ids = array_column(array_column($at_risk, 'goal'), 'id');
$todays_reminders = reminders_for_today($pdo, $uid);

// ---- The one trend chart: this week's Productivity Score forecast ----
$habit_forecast = get_forecast($pdo, $uid, 'habit');
$habit_ready = $habit_forecast && $habit_forecast['status'] === 'ok' && isset($habit_forecast['history']['productivity_score']);

if (!$habit_ready) {
    $prod_trend_content = null;
} else {
    $wlabels = [];
    $n_actual_weeks = count($habit_forecast['history']['weeks']);
    for ($i = 0; $i < $n_actual_weeks; $i++) $wlabels[] = 'Wk ' . ($i + 1);
    $n_fc_weeks = count($habit_forecast['forecast']['productivity_score']);
    for ($i = 0; $i < $n_fc_weeks; $i++) $wlabels[] = '+' . ($i + 1);
    $prod_trend_content = svg_line_chart($wlabels, [
        'Productivity score' => ['actual' => $habit_forecast['history']['productivity_score'], 'forecast' => $habit_forecast['forecast']['productivity_score'], 'color' => '#9391F5', 'ci_lower' => $habit_forecast['forecast']['productivity_score_lower'] ?? [], 'ci_upper' => $habit_forecast['forecast']['productivity_score_upper'] ?? []],
    ], 640, 220);
}
$prod_trend_footer = $habit_ready ? '<a href="insights.php#forecast" class="btn btn-ghost btn-sm">Full Insights →</a>' : null;

require_once __DIR__ . '/includes/header.php';

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$first_name = explode(' ', trim($user['full_name']))[0];
?>

<h2 style="font-size:24px; margin-bottom:2px;"><?= $greeting ?>, <?= htmlspecialchars($first_name) ?> 👋</h2>
<p style="color:var(--ink-soft); font-weight:600; font-size:14px;">Here's what's left today.</p>

<div class="day-strip">
    <?php foreach (week_dates() as $d):
        $dt = new DateTime($d);
        $isToday = $d === $today;
    ?>
    <div class="d-tile <?= $isToday ? 'today' : '' ?>">
        <div class="d-num"><?= $dt->format('j') ?></div>
        <div class="d-name"><?= $dt->format('D') ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="bento-grid">
    <div class="bento-cell">
        <h4>Today's progress</h4>
        <div class="big-ring">
            <svg width="130" height="130" viewBox="0 0 130 130">
                <circle class="ring-bg" cx="65" cy="65" r="52"></circle>
                <?php $today_c = 2*M_PI*52; $today_pct = $total_goals > 0 ? round(($done_today/$total_goals)*100) : 0; $today_off = $today_c - ($today_pct/100)*$today_c; ?>
                <circle class="ring-fg" cx="65" cy="65" r="52" stroke-dasharray="<?= $today_c ?>" stroke-dashoffset="<?= $today_off ?>"></circle>
            </svg>
            <div class="ring-center">
                <div class="big-num"><?= $today_pct ?>%</div>
                <div class="big-label"><?= $done_today ?>/<?= $total_goals ?> done</div>
            </div>
        </div>
    </div>
    <div class="bento-cell span-2">
        <h4>Today's check-ins</h4>
        <?php if ($total_goals == 0): ?>
            <div class="empty-state" style="padding:20px 10px;"><div class="em-ico">🌱</div><p>No goals yet. <a href="habits.php">Add your first one</a> to get started.</p></div>
        <?php elseif (empty($pending_today)): ?>
            <div class="empty-state" style="padding:20px 10px;"><div class="em-ico">🎉</div><p>You're all caught up for today. Nothing pending!</p></div>
        <?php else: ?>
            <?php foreach ($pending_today as $g): $is_at_risk = in_array($g['id'], $at_risk_goal_ids); ?>
            <div class="pending-row">
                <span class="pending-title">
                    <?= htmlspecialchars($g['title']) ?>
                    <span class="caption"><?= htmlspecialchars($g['cat_name']) ?><?php if ($is_at_risk): ?> · <span class="text-attention" style="font-weight:700;">🔥 streak at risk</span><?php endif; ?></span>
                </span>
                <div class="day-box checkin-pill" data-date="<?= $today ?>" data-goal="<?= $g['id'] ?>">Check in</div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="bento-cell" style="justify-content:center; gap:10px;">
        <h4 style="margin-bottom:4px;">Quick actions</h4>
        <a href="habits.php" class="btn btn-primary btn-sm btn-block">+ Add a goal</a>
        <a href="focus.php" class="btn btn-ghost btn-sm btn-block">⏱️ Start a focus session</a>
        <a href="mood.php" class="btn btn-ghost btn-sm btn-block">💛 Log today's mood</a>
    </div>
</div>

<div class="bento-grid">
<?= chart_card([
    'title' => 'Productivity trend',
    'info' => 'Your weekly Productivity Score, with the next two weeks forecast — see the full breakdown on Insights.',
    'span' => 3,
    'content' => $prod_trend_content,
    'chart_width' => 640,
    'empty_message' => 'No forecast yet — visit Insights and click Retrain, or check in on a few goals first.',
    'footer' => $prod_trend_footer,
]) ?>
    <div class="bento-cell anim-in">
        <h4>Needs attention</h4>
        <?php if (empty($at_risk) && empty($todays_reminders)): ?>
            <p style="font-size:13px; color:var(--ink-soft); font-weight:500; margin-top:8px;">Nothing urgent — you're on track.</p>
        <?php else: ?>
            <?php foreach (array_slice($at_risk, 0, 3) as $r): ?>
            <div class="reminder-chip tag-attention" style="display:block; margin-bottom:8px;">🔥 <?= htmlspecialchars($r['goal']['title']) ?> — <?= $r['streak'] ?>d streak</div>
            <?php endforeach; ?>
            <?php foreach (array_slice($todays_reminders, 0, 3) as $r): ?>
            <div class="reminder-chip" style="display:block; margin-bottom:8px;">⏰ <?= date('g:i a', strtotime($r['remind_time'])) ?> — <?= htmlspecialchars($r['title']) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
        <a href="habits.php" class="btn btn-ghost btn-sm btn-block" style="margin-top:6px;">Go check in →</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
