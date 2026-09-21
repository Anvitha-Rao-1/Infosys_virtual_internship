<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/narrative.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'Growth';
$nav = 'growth';

// ============================================================
// GROWTH — the payoff section, and the one place the Sprout metaphor
// is made literal.
//
// Every figure here is derived live, exactly as before: XP is check-ins
// x 10 plus badge bonuses, levels are XP / 150, and the weekly
// challenges are counted straight from the logs. Nothing is stored, so
// nothing can drift out of step with reality.
// ============================================================

evaluate_achievements($pdo, $uid);
$level_info = user_level_info($pdo, $uid);
$growth = nar_growth($pdo, $uid);

$all_achievements = $pdo->query("SELECT * FROM achievements ORDER BY xp_reward ASC")->fetchAll();
$stmt = $pdo->prepare("SELECT achievement_id, earned_at FROM user_achievements WHERE user_id=?");
$stmt->execute([$uid]);
$earned_map = [];
foreach ($stmt->fetchAll() as $r) $earned_map[$r['achievement_id']] = $r['earned_at'];

// ---- This week's challenges, counted live ----
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
    ['title' => 'Ten this week',  'icon' => '🗡️', 'desc' => 'Check in ten times between Monday and Sunday', 'progress' => $week_checkins, 'target' => 10, 'xp' => 30],
    ['title' => 'Checked in',     'icon' => '💛', 'desc' => 'Log how you are feeling on five days',          'progress' => $week_moods,    'target' => 5,  'xp' => 25],
    ['title' => 'Well rounded',   'icon' => '🧭', 'desc' => 'Keep habits going in three different areas',     'progress' => $distinct_cats, 'target' => 3,  'xp' => 20],
];

$earned_count = count($earned_map);
$total_badges = count($all_achievements);

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">Growth</p>
            <h1><?= htmlspecialchars($growth['stage_line']) ?></h1>
            <p class="hero-sub">
                Every check-in is one small decision. This is what
                <?= number_format($growth['checkins']) ?> of them look like stacked on top of each other.
            </p>
        </div>
        <div class="hero-stats">
            <div>
                <div class="hstat-n"><span data-count="<?= $level_info['level'] ?>"><?= $level_info['level'] ?></span></div>
                <div class="hstat-l">level</div>
            </div>
            <div>
                <div class="hstat-n"><span data-count="<?= $earned_count ?>"><?= $earned_count ?></span><span class="u">/<?= $total_badges ?></span></div>
                <div class="hstat-l">milestones reached</div>
            </div>
        </div>
    </div>
</header>

<div class="wrap">

<!-- ---------- The plant ---------- -->
<section class="ch enter">
    <div class="ch-head"><h2>Your sprout</h2></div>
    <p class="ch-lead">It grows on what you have actually done — check-ins, not intentions. Nothing here is decorative.</p>

    <div class="growth" data-grow>
        <div><?= nar_sprout_svg($growth['stage'], 200, 270) ?></div>
        <div>
            <span class="stage">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="5"/></svg>
                <?= htmlspecialchars($growth['stage_name']) ?>
            </span>
            <h2>
                <?= $growth['next_name']
                    ? nar_plural($growth['to_next'], 'check-in') . ' from ' . htmlspecialchars($growth['next_name']) . '.'
                    : 'Fully grown.' ?>
            </h2>
            <p class="growth-lead">
                <?= $growth['next_name']
                    ? 'Each stage takes longer than the last, which is rather the point — the plant is slow because the habit is real.'
                    : 'There is no stage after this one. What you have now is a routine, not a project.' ?>
            </p>
            <?php if ($growth['next_name']): ?>
            <div class="bar" style="--w:<?= $growth['pct_to_next'] ?>%"><i></i></div>
            <?php endif; ?>

            <div class="milestones">
                <div>
                    <div class="ms-n"><span data-count="<?= $growth['current_streak'] ?>"><?= $growth['current_streak'] ?></span></div>
                    <div class="ms-l">day run right now</div>
                </div>
                <div>
                    <div class="ms-n"><span data-count="<?= $growth['best_ever'] ?>"><?= $growth['best_ever'] ?></span></div>
                    <div class="ms-l">longest you have ever gone</div>
                </div>
                <div>
                    <div class="ms-n"><span data-count="<?= $level_info['xp'] ?>"><?= number_format($level_info['xp']) ?></span></div>
                    <div class="ms-l">points earned</div>
                </div>
            </div>
        </div>
    </div>

    <p style="font-size:13px; color:var(--ink-soft); margin-top:16px; max-width:64ch;">
        You are <?= $level_info['into_level'] ?> points into level <?= $level_info['level'] ?>.
        Every check-in is worth 10, and milestones below are worth more.
    </p>
</section>

<!-- ---------- This week ---------- -->
<section class="ch enter">
    <div class="ch-head"><h2>This week</h2></div>
    <p class="ch-lead">Three things to aim at between now and Sunday. They reset every Monday.</p>

    <div class="quests">
        <?php foreach ($challenges as $c):
            $done = $c['progress'] >= $c['target'];
            $pct = min(100, (int)round($c['progress'] / max(1, $c['target']) * 100));
        ?>
        <article class="quest enter <?= $done ? 'done' : '' ?>">
            <div class="quest-i"><?= $c['icon'] ?></div>
            <div>
                <div class="quest-t"><?= htmlspecialchars($c['title']) ?></div>
                <div class="quest-d"><?= htmlspecialchars($c['desc']) ?></div>
                <div class="qbar" style="--w:<?= $pct ?>%"><i></i></div>
            </div>
            <div class="quest-n">
                <?= $done ? 'Done' : min($c['progress'], $c['target']) . ' of ' . $c['target'] ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<!-- ---------- Milestones ---------- -->
<section class="ch enter">
    <div class="ch-head"><h2>Milestones</h2></div>
    <p class="ch-lead">
        <?= $earned_count === 0
            ? 'None yet. The first one arrives with your very first check-in.'
            : 'You have reached ' . $earned_count . ' of ' . $total_badges . '. The greyed-out ones are still ahead of you.' ?>
    </p>

    <div class="badges">
        <?php foreach ($all_achievements as $a):
            $got = isset($earned_map[$a['id']]);
        ?>
        <div class="badge <?= $got ? 'got' : 'locked' ?>">
            <div class="badge-i"><?= $a['icon'] ?></div>
            <div class="badge-t"><?= htmlspecialchars($a['title']) ?></div>
            <div class="badge-d"><?= htmlspecialchars($a['description']) ?></div>
            <div class="badge-w">
                <?= $got ? 'Reached ' . date('j M Y', strtotime($earned_map[$a['id']])) : '+' . $a['xp_reward'] . ' points' ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

</div>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
