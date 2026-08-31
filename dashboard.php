<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$page_title = 'Dashboard';
$active = 'dashboard';

$uid = $user['id'];

// Overall stats
$total_goals = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
$total_goals->execute([$uid]);
$total_goals = $total_goals->fetch()['c'];

$today = date('Y-m-d');
$done_today = $pdo->prepare("SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id WHERE g.user_id=? AND gl.log_date=? AND gl.status='done'");
$done_today->execute([$uid, $today]);
$done_today = $done_today->fetch()['c'];

// best streak across all goals
$stmt = $pdo->prepare("SELECT id FROM goals WHERE user_id=? AND is_active=1");
$stmt->execute([$uid]);
$goal_ids = array_column($stmt->fetchAll(), 'id');
$best_streak = 0;
foreach ($goal_ids as $gid) {
    $s = current_streak($pdo, $gid);
    if ($s > $best_streak) $best_streak = $s;
}

$week_total_possible = count($goal_ids) * 7;
$week_total_done = 0;
foreach ($goal_ids as $gid) $week_total_done += count(week_logs($pdo, $gid));
$week_rate = $week_total_possible > 0 ? round(($week_total_done / $week_total_possible) * 100) : 0;

$categories = get_all_categories($pdo);
$level_info = user_level_info($pdo, $uid);
$score = wellness_score($pdo, $uid);
evaluate_achievements($pdo, $uid);

// goal count per category
$cat_counts = [];
$stmt = $pdo->prepare("SELECT category_id, COUNT(*) c FROM goals WHERE user_id=? AND is_active=1 GROUP BY category_id");
$stmt->execute([$uid]);
foreach ($stmt->fetchAll() as $row) $cat_counts[$row['category_id']] = $row['c'];

// today's goal list across all categories
$stmt = $pdo->prepare("SELECT g.*, c.name cat_name, c.color cat_color, c.slug cat_slug,
    (SELECT COUNT(*) FROM goal_logs WHERE goal_id=g.id AND log_date=? AND status='done') as done_today
    FROM goals g JOIN categories c ON c.id=g.category_id
    WHERE g.user_id=? AND g.is_active=1 ORDER BY g.created_at DESC LIMIT 6");
$stmt->execute([$today, $uid]);
$recent_goals = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$first_name = explode(' ', trim($user['full_name']))[0];
?>

<h2 style="font-size:24px; margin-bottom:2px;"><?= $greeting ?>, <?= htmlspecialchars($first_name) ?> 👋</h2>
<p style="color:var(--ink-soft); font-weight:600; font-size:14px;">Here's how your habits are looking this week.</p>

<div class="day-strip">
    <?php foreach (week_dates() as $d):
        $dt = new DateTime($d);
        $isToday = $d === date('Y-m-d');
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
    <div class="bento-cell">
        <div class="stat-ico">🎯</div>
        <div class="stat-num" style="font-family:'Nunito',sans-serif; font-weight:800; font-size:34px;"><?= $total_goals ?></div>
        <div class="stat-label" style="font-size:13px; color:var(--ink-soft); font-weight:600; margin-top:4px;">Active goals</div>
    </div>
    <div class="bento-cell">
        <div class="stat-ico">🔥</div>
        <div class="stat-num" style="font-family:'Nunito',sans-serif; font-weight:800; font-size:34px;"><?= $best_streak ?></div>
        <div class="stat-label" style="font-size:13px; color:var(--ink-soft); font-weight:600; margin-top:4px;">Best current streak</div>
    </div>
    <div class="bento-cell" style="justify-content:center; gap:10px;">
        <h4 style="margin-bottom:4px;">Quick actions</h4>
        <a href="academic.php" class="btn btn-primary btn-sm btn-block">+ Add a goal</a>
        <a href="mood.php" class="btn btn-ghost btn-sm btn-block">💛 Log today's mood</a>
    </div>
</div>

<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-ico">✅</div>
        <div class="stat-num"><?= $done_today ?></div>
        <div class="stat-label">Checked in today</div>
    </div>
    <div class="stat-card">
        <div class="stat-ico">📈</div>
        <div class="stat-num"><?= $week_rate ?>%</div>
        <div class="stat-label">This week's completion</div>
    </div>
    <div class="stat-card">
        <div class="stat-ico">⚡</div>
        <div class="stat-num">Lv.<?= $level_info['level'] ?></div>
        <div class="stat-label"><?= $level_info['xp'] ?> total XP</div>
    </div>
    <div class="stat-card">
        <div class="stat-ico">💛</div>
        <div class="stat-num"><?= $score ?? '—' ?></div>
        <div class="stat-label">Wellness score</div>
    </div>
</div>

<div class="section-title"><h2>Your categories</h2></div>
<div class="cat-grid">
    <?php foreach ($categories as $cat): ?>
    <a href="<?= htmlspecialchars($cat['slug']) ?>.php" class="cat-card" style="border-top-color: <?= htmlspecialchars($cat['color']) ?>">
        <div class="cat-ico"><?= $cat['icon'] ?></div>
        <h3><?= htmlspecialchars($cat['name']) ?></h3>
        <p><?= $cat_counts[$cat['id']] ?? 0 ?> goal<?= (($cat_counts[$cat['id']] ?? 0) == 1) ? '' : 's' ?></p>
    </a>
    <?php endforeach; ?>
</div>

<div class="section-title">
    <h2>Recently added goals</h2>
    <a href="profile.php" class="btn btn-ghost btn-sm">View all in profile</a>
</div>
<?php if (empty($recent_goals)): ?>
    <div class="card empty-state">
        <div class="em-ico">🌱</div>
        <p>No goals yet. Head into a category page to add your first one.</p>
    </div>
<?php else: ?>
<div class="goal-list">
    <?php foreach ($recent_goals as $g):
        $pct = week_percent($pdo, $g['id']);
        $circumference = 2 * M_PI * 24;
        $offset = $circumference - ($pct / 100) * $circumference;
    ?>
    <div class="goal-card">
        <div class="streak-ring">
            <svg width="62" height="62" viewBox="0 0 62 62">
                <circle class="ring-bg" cx="31" cy="31" r="24"></circle>
                <circle class="ring-fg" cx="31" cy="31" r="24" stroke-dasharray="<?= $circumference ?>" stroke-dashoffset="<?= $offset ?>"></circle>
            </svg>
            <div class="ring-num"><?= $pct ?>%</div>
        </div>
        <div class="goal-info">
            <h3><?= htmlspecialchars($g['title']) ?></h3>
            <p><?= htmlspecialchars($g['description'] ?: 'No description') ?></p>
            <span class="goal-tag" style="background:<?= $g['cat_color'] ?>22; color:<?= $g['cat_color'] ?>"><?= htmlspecialchars($g['cat_name']) ?></span>
        </div>
        <?php if ($g['done_today']): ?>
            <span class="goal-tag" style="background:#2F523322;color:#2F5233">Done today</span>
        <?php else: ?>
            <a href="<?= $g['cat_slug'] ?>.php" class="btn btn-primary btn-sm">Check in</a>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
