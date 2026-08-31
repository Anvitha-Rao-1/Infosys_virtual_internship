<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'AI Coach';
$active = 'coach';
$first_name = explode(' ', trim($user['full_name']))[0];

// ---- Goals not yet checked in today (smart reminders) ----
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT g.*, c.name cat_name, c.slug cat_slug FROM goals g JOIN categories c ON c.id=g.category_id
    WHERE g.user_id=? AND g.is_active=1 AND g.id NOT IN (SELECT goal_id FROM goal_logs WHERE log_date=? AND status='done')");
$stmt->execute([$uid, $today]);
$pending_today = $stmt->fetchAll();

// ---- Streaks close to breaking (had a streak, but not done today) ----
$at_risk = [];
foreach ($pending_today as $g) {
    $s = current_streak($pdo, $g['id']);
    if ($s >= 3) $at_risk[] = ['goal' => $g, 'streak' => $s];
}
usort($at_risk, fn($a,$b) => $b['streak'] <=> $a['streak']);

// ---- Category with lowest weekly completion (recommendation target) ----
$stmt = $pdo->prepare("SELECT c.name, c.slug, COUNT(g.id) goal_count,
    (SELECT COUNT(*) FROM goal_logs gl JOIN goals g2 ON g2.id=gl.goal_id WHERE g2.category_id=c.id AND g2.user_id=? AND gl.status='done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) as done_week
    FROM categories c JOIN goals g ON g.category_id=c.id AND g.user_id=? AND g.is_active=1
    GROUP BY c.id HAVING goal_count > 0");
$stmt->execute([$uid, $uid]);
$cats = $stmt->fetchAll();
$weakest = null;
foreach ($cats as $c) {
    $rate = $c['done_week'] / ($c['goal_count'] * 7);
    $c['rate'] = $rate;
    if ($weakest === null || $rate < $weakest['rate']) $weakest = $c;
}

// ---- Mood vs completion correlation ----
$stmt = $pdo->prepare("SELECT ml.log_date, ml.mood, (SELECT COUNT(*) FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id WHERE g.user_id=ml.user_id AND gl.log_date=ml.log_date AND gl.status='done') as done_count
    FROM mood_logs ml WHERE ml.user_id=? AND ml.log_date >= DATE_SUB(CURDATE(), INTERVAL 20 DAY)");
$stmt->execute([$uid]);
$mood_rows = $stmt->fetchAll();
$low_mood_days = array_filter($mood_rows, fn($r) => in_array($r['mood'], ['stressed','sad']));
$good_mood_days = array_filter($mood_rows, fn($r) => in_array($r['mood'], ['happy','calm']));
$mood_insight = null;
if (count($low_mood_days) >= 2 && count($good_mood_days) >= 2) {
    $low_avg = array_sum(array_column($low_mood_days, 'done_count')) / count($low_mood_days);
    $good_avg = array_sum(array_column($good_mood_days, 'done_count')) / count($good_mood_days);
    if ($good_avg > $low_avg * 1.15) {
        $mood_insight = "You complete about " . round((($good_avg - $low_avg) / max($low_avg,0.1)) * 100) . "% more habits on days you feel happy or calm, compared to stressed or low days.";
    }
}

// ---- Progress trend (last 2 weeks vs previous 2 weeks) ----
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id WHERE g.user_id=? AND gl.status='done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)");
$stmt->execute([$uid]); $recent_2wk = (int)$stmt->fetch()['c'];
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id WHERE g.user_id=? AND gl.status='done' AND gl.log_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 27 DAY) AND DATE_SUB(CURDATE(), INTERVAL 14 DAY)");
$stmt->execute([$uid]); $prev_2wk = (int)$stmt->fetch()['c'];
$trend = null;
if ($prev_2wk > 0) {
    $change = round((($recent_2wk - $prev_2wk) / $prev_2wk) * 100);
    $trend = $change;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="coach-hero">
    <div class="coach-avatar">✨</div>
    <div>
        <h3>Hey <?= htmlspecialchars($first_name) ?>, here's your coaching summary</h3>
        <p>Personalized insights generated from your real check-in and mood data.</p>
    </div>
</div>

<div class="section-title"><h2>Smart reminders — today</h2></div>
<div class="card" style="margin-bottom:26px;">
    <?php if (empty($pending_today)): ?>
        <div class="empty-state"><div class="em-ico">🎉</div><p>You're all caught up for today. Nothing pending!</p></div>
    <?php else: ?>
        <?php foreach ($pending_today as $g): ?>
        <a href="<?= $g['cat_slug'] ?>.php" class="reminder-chip">⏰ <?= htmlspecialchars($g['title']) ?></a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="section-title"><h2>Habit insights & recommendations</h2></div>
<div class="card">
    <?php if (!empty($at_risk)): $top = $at_risk[0]; ?>
    <div class="insight-card">
        <div class="ins-ico">🔥</div>
        <p>Your <strong><?= htmlspecialchars($top['goal']['title']) ?></strong> streak is at <strong><?= $top['streak'] ?> days</strong> and isn't checked in yet today — a quick check-in keeps it alive.</p>
    </div>
    <?php endif; ?>

    <?php if ($weakest && $weakest['rate'] < 0.5): ?>
    <div class="insight-card">
        <div class="ins-ico">💡</div>
        <p><strong><?= htmlspecialchars($weakest['name']) ?></strong> is your least consistent category this week (<?= round($weakest['rate']*100) ?>%). Try picking just one small win there today.</p>
    </div>
    <?php endif; ?>

    <?php if ($mood_insight): ?>
    <div class="insight-card">
        <div class="ins-ico">💛</div>
        <p><?= htmlspecialchars($mood_insight) ?> Keeping habits light on tough days can help protect your streaks.</p>
    </div>
    <?php endif; ?>

    <?php if ($trend !== null): ?>
    <div class="insight-card">
        <div class="ins-ico"><?= $trend >= 0 ? '📈' : '📉' ?></div>
        <p>Your check-ins are <strong><?= $trend >= 0 ? 'up' : 'down' ?> <?= abs($trend) ?>%</strong> over the last 2 weeks compared to the 2 weeks before that.</p>
    </div>
    <?php endif; ?>

    <?php if (empty($at_risk) && !$mood_insight && $trend === null && (!$weakest || $weakest['rate'] >= 0.5)): ?>
    <div class="empty-state"><div class="em-ico">✨</div><p>Keep checking in daily — insights get sharper the more data you log.</p></div>
    <?php endif; ?>
</div>

<div class="alert" style="background:var(--lightgray); color:var(--ink-soft); margin-top:24px; font-weight:500;">
    ℹ️ These recommendations are generated by analysing patterns in your own logged data (streaks, category rates, mood correlation, and trend comparisons) — not a live external AI API call.
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
