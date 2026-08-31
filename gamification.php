<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Rewards';
$active = 'gamification';

evaluate_achievements($pdo, $uid);
$level_info = user_level_info($pdo, $uid);

$all_achievements = $pdo->query("SELECT * FROM achievements ORDER BY xp_reward ASC")->fetchAll();
$stmt = $pdo->prepare("SELECT achievement_id, earned_at FROM user_achievements WHERE user_id=?");
$stmt->execute([$uid]);
$earned_map = [];
foreach ($stmt->fetchAll() as $r) $earned_map[$r['achievement_id']] = $r['earned_at'];

// ---- Weekly challenges (computed live from real data, no separate table needed) ----
$dates = week_dates();
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
    WHERE g.user_id=? AND gl.status='done' AND gl.log_date BETWEEN ? AND ?");
$stmt->execute([$uid, $dates[0], end($dates)]);
$week_checkins = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM mood_logs WHERE user_id=? AND log_date BETWEEN ? AND ?");
$stmt->execute([$uid, $dates[0], end($dates)]);
$week_moods = (int)$stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT category_id) c FROM goals WHERE user_id=? AND is_active=1");
$stmt->execute([$uid]);
$distinct_cats = (int)$stmt->fetch()['c'];

$challenges = [
    ['title' => 'Weekly Warrior', 'icon' => '🗡️', 'desc' => 'Log 10 check-ins this week', 'progress' => $week_checkins, 'target' => 10, 'xp' => 30],
    ['title' => 'Mood Tuned In',  'icon' => '💛', 'desc' => 'Log your mood 5 days this week', 'progress' => $week_moods, 'target' => 5, 'xp' => 25],
    ['title' => 'Explorer',       'icon' => '🧭', 'desc' => 'Have goals in 3+ categories', 'progress' => $distinct_cats, 'target' => 3, 'xp' => 20],
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="level-hero">
    <div>
        <div style="font-size:13px; font-weight:700; opacity:.75;">Your level</div>
        <div class="lv-num">Lv. <?= $level_info['level'] ?></div>
        <div class="lv-bar"><span style="width:<?= $level_info['pct'] ?>%"></span></div>
        <div class="lv-xp"><?= $level_info['into_level'] ?> / <?= XP_PER_LEVEL ?> XP to next level · <?= $level_info['xp'] ?> total XP</div>
    </div>
    <div style="font-size:64px;">⚡</div>
</div>

<div class="section-title"><h2>Weekly challenges</h2></div>
<?php foreach ($challenges as $ch):
    $pct = min(100, round(($ch['progress'] / $ch['target']) * 100));
    $complete = $ch['progress'] >= $ch['target'];
?>
<div class="challenge-card">
    <div class="ch-top">
        <h4><?= $ch['icon'] ?> <?= htmlspecialchars($ch['title']) ?> <?= $complete ? '✅' : '' ?></h4>
        <span class="goal-tag" style="background:var(--lime) !important;">+<?= $ch['xp'] ?> XP</span>
    </div>
    <p style="font-size:12.5px; color:var(--ink-soft); font-weight:600; margin-bottom:10px;"><?= htmlspecialchars($ch['desc']) ?> · <?= min($ch['progress'],$ch['target']) ?>/<?= $ch['target'] ?></p>
    <div class="challenge-bar"><span style="width:<?= $pct ?>%"></span></div>
</div>
<?php endforeach; ?>

<div class="section-title"><h2>Achievements</h2></div>
<div class="badge-grid">
    <?php foreach ($all_achievements as $a):
        $isEarned = isset($earned_map[$a['id']]);
    ?>
    <div class="badge-card <?= $isEarned ? 'earned' : '' ?>">
        <div class="b-ico"><?= $a['icon'] ?></div>
        <h4><?= htmlspecialchars($a['title']) ?></h4>
        <p><?= htmlspecialchars($a['description']) ?></p>
        <span class="b-xp">+<?= $a['xp_reward'] ?> XP</span>
        <?php if ($isEarned): ?><p style="margin-top:8px; font-size:10.5px; color:var(--ink-soft);">Earned <?= date('d M Y', strtotime($earned_map[$a['id']])) ?></p><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
