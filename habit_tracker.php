<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Habit Tracker';
$active = 'habit_tracker';

$h_score = habit_score($pdo, $uid);
$best_streak = overall_best_streak($pdo, $uid);
$total_checkins = total_checkins($pdo, $uid);
$goals_with_progress = active_goals_with_progress($pdo, $uid, 50);

// Live week-check strip per habit, so you can actually check in from this
// page (not just view progress) — matches the day-box pattern used on
// Activity Tracker, wired to the same toggle_log.php AJAX endpoint.
$week_dates = week_dates();
$day_labels = ['M','T','W','T','F','S','S'];
$today_str = date('Y-m-d');
$stmt = $pdo->prepare("SELECT id FROM goals WHERE user_id=? AND is_active=1");
$stmt->execute([$uid]);
$goal_id_list = array_column($stmt->fetchAll(), 'id');
$logged_by_goal = [];
if (!empty($goal_id_list)) {
    $placeholders = implode(',', array_fill(0, count($goal_id_list), '?'));
    $stmt = $pdo->prepare("SELECT goal_id, log_date FROM goal_logs WHERE goal_id IN ($placeholders) AND status='done' AND log_date BETWEEN ? AND ?");
    $stmt->execute(array_merge($goal_id_list, [$week_dates[0], end($week_dates)]));
    foreach ($stmt->fetchAll() as $r) $logged_by_goal[$r['goal_id']][$r['log_date']] = true;
}

// ---- Heatmap: last 84 days ----
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
function ht_heat_color($count, $max) {
    if ($count == 0) return '#EFEFEF';
    $ratio = $count / $max;
    if ($ratio > 0.75) return '#8FAE8B';
    if ($ratio > 0.5) return '#B9F0D3';
    if ($ratio > 0.25) return '#DDF5E5';
    return '#EEF9F1';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Habit score
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Your 4-week rolling completion rate across all active goals — how consistent you've been lately, not just today.</span></span>
        </h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:30px;">
            <?php if ($h_score !== null): ?><span class="count-up" data-target="<?= $h_score ?>" data-suffix="/100">0/100</span><?php else: ?>—<?php endif; ?>
        </div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Best current streak</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:30px;"><span class="count-up" data-target="<?= $best_streak['days'] ?>" data-suffix=" days">0 days</span></div>
        <?php if ($best_streak['title']): ?><div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;"><?= htmlspecialchars($best_streak['title']) ?></div><?php endif; ?>
    </div>
    <div class="bento-cell anim-in">
        <h4>Total check-ins</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:30px;"><span class="count-up" data-target="<?= $total_checkins ?>">0</span></div>
    </div>
    <div class="bento-cell anim-in" style="justify-content:center; gap:10px;">
        <h4 style="margin-bottom:4px;">Quick actions</h4>
        <a href="activity.php" class="btn btn-primary btn-sm btn-block">Check in today</a>
        <a href="goals.php" class="btn btn-ghost btn-sm btn-block">Manage goals</a>
    </div>
</div>

<div class="card anim-in" style="margin-bottom:24px;">
    <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:14px;">
        Check-in heatmap · last 12 weeks
        <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Each square is one day. Darker = more check-ins logged that day, across all your active goals.</span></span>
    </h4>
    <div class="heatmap">
        <?php foreach ($heat_days as $d): $c = $heat_by_date[$d] ?? 0; ?>
        <div class="hm-cell" style="background:<?= ht_heat_color($c, $max_heat) ?>" title="<?= $d ?>: <?= $c ?> check-in<?= $c==1?'':'s' ?>"></div>
        <?php endforeach; ?>
    </div>
    <div class="heatmap-legend">
        Less <div class="hm-cell" style="background:#EFEFEF"></div><div class="hm-cell" style="background:#EEF9F1"></div><div class="hm-cell" style="background:#DDF5E5"></div><div class="hm-cell" style="background:#B9F0D3"></div><div class="hm-cell" style="background:#8FAE8B"></div> More
    </div>
</div>

<div class="section-title">
    <h2>Every habit, at a glance</h2>
    <span style="font-size:12px; color:var(--ink-soft); font-weight:600;">Tap a day box to check in — no need to leave this page</span>
</div>
<?php if (empty($goals_with_progress)): ?>
    <div class="card empty-state">
        <div class="em-ico">🌱</div>
        <p>No active goals yet — add one from <a href="goals.php">Goals</a> to see it here.</p>
    </div>
<?php else: ?>
<div class="goal-list">
    <?php foreach ($goals_with_progress as $g):
        $gid = $g['id'];
        $logged = $logged_by_goal[$gid] ?? [];
        $pct = $g['pct'];
        $circumference = 2 * M_PI * 24;
        $offset = $circumference - ($pct / 100) * $circumference;
    ?>
    <div class="goal-card" data-goal-id="<?= $gid ?>">
        <div class="streak-ring">
            <svg width="62" height="62" viewBox="0 0 62 62">
                <circle class="ring-bg" cx="31" cy="31" r="24"></circle>
                <circle class="ring-fg" cx="31" cy="31" r="24" stroke-dasharray="<?= $circumference ?>" stroke-dashoffset="<?= $offset ?>"></circle>
            </svg>
            <div class="ring-num"><?= $pct ?>%</div>
        </div>
        <div class="goal-info">
            <h3><?= htmlspecialchars($g['title']) ?></h3>
            <p><?= htmlspecialchars($g['category']) ?> · ~<?= $g['est_minutes'] ?> min/session</p>
            <span class="goal-tag">🔥 <?= $g['streak'] ?>d streak</span>
        </div>
        <div class="week-check">
            <?php foreach ($week_dates as $i => $d):
                $isFuture = $d > $today_str;
                $isDone = isset($logged[$d]);
                $cls = $isFuture ? 'future' : ($isDone ? 'done' : '');
            ?>
            <div class="day-box <?= $cls ?>" data-date="<?= $d ?>" data-goal="<?= $gid ?>" title="<?= $d ?>">
                <?= $day_labels[$i] ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
