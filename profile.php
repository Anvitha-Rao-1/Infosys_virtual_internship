<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'My Profile';
$active = 'profile';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['full_name'] ?? '');
    if ($name !== '') {
        $pdo->prepare("UPDATE users SET full_name=? WHERE id=?")->execute([$name, $uid]);
        $user = current_user();
        $success = 'Profile updated.';
    }
}

$stmt = $pdo->prepare("SELECT g.*, c.name cat_name, c.color cat_color, c.icon cat_icon, c.slug cat_slug
    FROM goals g JOIN categories c ON c.id=g.category_id
    WHERE g.user_id=? AND g.is_active=1 ORDER BY g.created_at DESC");
$stmt->execute([$uid]);
$goals = $stmt->fetchAll();

$dates = week_dates();
$day_labels = ['M','T','W','T','F','S','S'];
$today_str = date('Y-m-d');

require_once __DIR__ . '/includes/header.php';
?>

<div class="profile-head">
    <span class="avatar" style="background:<?= htmlspecialchars($user['avatar_color']) ?>; font-size:24px;"><?= initials($user['full_name']) ?></span>
    <div>
        <h2><?= htmlspecialchars($user['full_name']) ?></h2>
        <p><?= htmlspecialchars($user['email']) ?> · Member since <?= date('d M Y', strtotime($user['created_at'])) ?></p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:28px; max-width:480px;">
    <h3 style="margin-bottom:16px;">Edit profile</h3>
    <form method="POST">
        <div class="field">
            <label>Full name</label>
            <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>
        </div>
        <div class="field">
            <label>Email</label>
            <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled style="background:#F1ECDE;">
        </div>
        <button type="submit" name="update_profile" class="btn btn-primary">Save changes</button>
    </form>
</div>

<div class="section-title"><h2>My habit tracker · all goals</h2></div>
<?php if (empty($goals)): ?>
    <div class="card empty-state">
        <div class="em-ico">🌱</div>
        <p>You haven't added any goals yet. Visit a category page to add your first one.</p>
    </div>
<?php else: ?>
<div class="goal-list">
    <?php foreach ($goals as $g):
        $pct = week_percent($pdo, $g['id']);
        $streak = current_streak($pdo, $g['id']);
        $circumference = 2 * M_PI * 24;
        $offset = $circumference - ($pct / 100) * $circumference;
        $logged = week_logs($pdo, $g['id']);
        $logged_flip = array_flip($logged);
        $isNew = (strtotime($g['created_at']) > strtotime('-3 days'));
    ?>
    <div class="goal-card" data-goal-id="<?= $g['id'] ?>">
        <div class="streak-ring">
            <svg width="62" height="62" viewBox="0 0 62 62">
                <circle class="ring-bg" cx="31" cy="31" r="24"></circle>
                <circle class="ring-fg" cx="31" cy="31" r="24" stroke-dasharray="<?= $circumference ?>" stroke-dashoffset="<?= $offset ?>"></circle>
            </svg>
            <div class="ring-num"><?= $pct ?>%</div>
        </div>
        <div class="goal-info">
            <h3><?= htmlspecialchars($g['title']) ?> <?php if ($isNew): ?><span class="goal-tag" style="background:#E17B5B22;color:#E17B5B;">New</span><?php endif; ?></h3>
            <p>🔥 <?= $streak ?> day streak</p>
            <span class="goal-tag" style="background:<?= $g['cat_color'] ?>22; color:<?= $g['cat_color'] ?>"><?= $g['cat_icon'] ?> <?= htmlspecialchars($g['cat_name']) ?></span>
        </div>
        <div class="week-check">
            <?php foreach ($dates as $i => $d):
                $isFuture = $d > $today_str;
                $isDone = isset($logged_flip[$d]);
                $cls = $isFuture ? 'future' : ($isDone ? 'done' : '');
            ?>
            <div class="day-box <?= $cls ?>" data-date="<?= $d ?>" data-goal="<?= $g['id'] ?>" title="<?= $d ?>">
                <?= $day_labels[$i] ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
