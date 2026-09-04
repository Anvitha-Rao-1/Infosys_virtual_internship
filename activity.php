<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Activity Tracker';
$active = 'activity';

$categories = get_all_categories($pdo);
$filter = $_GET['cat'] ?? 'all';

// Handle "add goal" form submission (quick-add while checking in)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_goal'])) {
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $cat_id = (int)($_POST['category_id'] ?? 0);
    $freq  = ($_POST['frequency'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';
    $target = max(1, min(7, (int)($_POST['target_per_week'] ?? 7)));
    $est_minutes = max(1, min(240, (int)($_POST['est_minutes'] ?? 20)));
    if ($title !== '' && $cat_id > 0) {
        $stmt = $pdo->prepare("INSERT INTO goals (user_id, category_id, title, description, frequency, target_per_week, est_minutes) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$uid, $cat_id, $title, $desc, $freq, $target, $est_minutes]);
    }
    header('Location: activity.php?cat=' . urlencode($filter) . '&added=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_goal'])) {
    $stmt = $pdo->prepare("DELETE FROM goals WHERE id=? AND user_id=?");
    $stmt->execute([(int)$_POST['goal_id'], $uid]);
    header('Location: activity.php?cat=' . urlencode($filter));
    exit;
}

$query = "SELECT g.*, c.slug AS cat_slug, c.name AS cat_name FROM goals g JOIN categories c ON c.id=g.category_id WHERE g.user_id=? AND g.is_active=1";
$params = [$uid];
if ($filter !== 'all') {
    $query .= " AND c.slug=?";
    $params[] = $filter;
}
$query .= " ORDER BY g.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$goals = $stmt->fetchAll();

$dates = week_dates();
$day_labels = ['M','T','W','T','F','S','S'];
$today_str = date('Y-m-d');

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success">Goal added 🌱</div><?php endif; ?>

<div class="day-strip">
    <?php foreach ($dates as $i => $d):
        $dt = new DateTime($d);
        $isToday = $d === $today_str;
    ?>
    <div class="d-tile <?= $isToday ? 'today' : '' ?>">
        <div class="d-num"><?= $dt->format('j') ?></div>
        <div class="d-name"><?= $day_labels[$i] ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="section-title">
    <h2>📋 Activity Tracker</h2>
    <button class="btn btn-violet btn-sm" onclick="document.getElementById('addGoalModal').classList.add('show')">+ Add goal</button>
</div>

<div class="seg-tabs" style="flex-wrap:wrap; height:auto;">
    <button class="<?= $filter === 'all' ? 'active' : '' ?>" onclick="location.href='activity.php?cat=all'">All</button>
    <?php foreach ($categories as $c): ?>
    <button class="<?= $filter === $c['slug'] ? 'active' : '' ?>" onclick="location.href='activity.php?cat=<?= $c['slug'] ?>'"><?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?></button>
    <?php endforeach; ?>
</div>

<?php if (empty($goals)): ?>
    <div class="card empty-state">
        <div class="em-ico">📋</div>
        <p>No goals in this view yet. Add one to start tracking your streak.</p>
    </div>
<?php else: ?>
<div class="goal-list">
    <?php foreach ($goals as $g):
        $pct = week_percent($pdo, $g['id']);
        $circumference = 2 * M_PI * 24;
        $offset = $circumference - ($pct / 100) * $circumference;
        $logged = week_logs($pdo, $g['id']);
        $logged_flip = array_flip($logged);
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
            <h3><?= htmlspecialchars($g['title']) ?></h3>
            <p><?= htmlspecialchars($g['description'] ?: ucfirst($g['frequency']) . ' goal') ?></p>
            <span class="goal-tag"><?= htmlspecialchars($g['cat_name']) ?></span>
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
        <div class="goal-actions">
            <form method="POST" onsubmit="return confirm('Delete this goal and all its history?');">
                <input type="hidden" name="goal_id" value="<?= $g['id'] ?>">
                <button type="submit" name="delete_goal" class="icon-btn" title="Delete goal">🗑</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add Goal Modal -->
<div class="modal-overlay" id="addGoalModal">
    <div class="modal-box">
        <h3>New goal</h3>
        <form method="POST">
            <div class="field">
                <label>Goal title</label>
                <input type="text" name="title" placeholder="e.g. Read for 30 minutes" required>
            </div>
            <div class="field">
                <label>Description (optional)</label>
                <input type="text" name="description" placeholder="Any notes about this goal">
            </div>
            <div class="field">
                <label>Category</label>
                <select name="category_id" required>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter === $c['slug'] ? 'selected' : '' ?>><?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="field">
                    <label>Frequency</label>
                    <select name="frequency">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                    </select>
                </div>
                <div class="field">
                    <label>Target days / week</label>
                    <input type="number" name="target_per_week" min="1" max="7" value="7">
                </div>
            </div>
            <div class="field">
                <label>Typical time per check-in (minutes)</label>
                <input type="number" name="est_minutes" min="1" max="240" value="20">
            </div>
            <div class="modal-close-row">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addGoalModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_goal" class="btn btn-primary">Save goal</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
