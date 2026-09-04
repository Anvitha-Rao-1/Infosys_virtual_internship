<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Goals';
$active = 'goals';

$categories = get_all_categories($pdo);

// ---- Add goal ----
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
    header('Location: goals.php?added=1');
    exit;
}

// ---- Edit goal ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_goal'])) {
    $gid = (int)($_POST['goal_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $cat_id = (int)($_POST['category_id'] ?? 0);
    $freq  = ($_POST['frequency'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';
    $target = max(1, min(7, (int)($_POST['target_per_week'] ?? 7)));
    $est_minutes = max(1, min(240, (int)($_POST['est_minutes'] ?? 20)));
    if ($title !== '' && $cat_id > 0 && $gid > 0) {
        $stmt = $pdo->prepare("UPDATE goals SET title=?, description=?, category_id=?, frequency=?, target_per_week=?, est_minutes=? WHERE id=? AND user_id=?");
        $stmt->execute([$title, $desc, $cat_id, $freq, $target, $est_minutes, $gid, $uid]);
    }
    header('Location: goals.php?updated=1');
    exit;
}

// ---- Delete goal ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_goal'])) {
    $stmt = $pdo->prepare("DELETE FROM goals WHERE id=? AND user_id=?");
    $stmt->execute([(int)$_POST['goal_id'], $uid]);
    header('Location: goals.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT g.*, c.name AS cat_name, c.icon AS cat_icon, c.color AS cat_color
     FROM goals g JOIN categories c ON c.id = g.category_id
     WHERE g.user_id=? AND g.is_active=1 ORDER BY g.created_at DESC"
);
$stmt->execute([$uid]);
$goals = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success">Goal added 🎯</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Goal updated ✏️</div><?php endif; ?>

<div class="section-title">
    <h2>All goals</h2>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('addGoalModal').classList.add('show')">+ Add goal</button>
</div>
<p style="color:var(--ink-soft); font-weight:500; font-size:13.5px; margin-top:-14px; margin-bottom:22px;">
    Manage every goal in one place — day-to-day check-ins still happen on <a href="activity.php" style="color:var(--lavender-deep); font-weight:700;">Activity Tracker</a>.
</p>

<?php if (empty($goals)): ?>
    <div class="card empty-state">
        <div class="em-ico">🎯</div>
        <p>No goals yet. Add your first one above.</p>
    </div>
<?php else: ?>
<div class="card" style="padding:8px 26px;">
    <?php foreach ($goals as $g):
        $pct = week_percent($pdo, $g['id']);
        $streak = current_streak($pdo, $g['id']);
        $g_json = htmlspecialchars(json_encode($g), ENT_QUOTES);
    ?>
    <div class="tracker-row">
        <span style="min-width:0; flex-shrink:1;">
            <strong><?= htmlspecialchars($g['title']) ?></strong>
            <span style="color:var(--ink-soft); font-weight:500;"> · <?= $g['cat_icon'] ?> <?= htmlspecialchars($g['cat_name']) ?> · <?= ucfirst($g['frequency']) ?> · ~<?= (int)$g['est_minutes'] ?> min/session</span>
        </span>
        <div class="tracker-bar" style="max-width:140px;"><span class="grow-in" style="width:<?= $pct ?>%; background: var(--sky-deep);"></span></div>
        <span class="ho-streak" style="margin-right:12px;">🔥 <?= $streak ?>d</span>
        <div class="goal-actions">
            <button type="button" class="icon-btn" title="Edit" onclick='openEditGoal(<?= $g_json ?>)'>✏️</button>
            <form method="POST" onsubmit="return confirm('Delete this goal and all its history?');" style="display:inline;">
                <input type="hidden" name="goal_id" value="<?= $g['id'] ?>">
                <button type="submit" name="delete_goal" class="icon-btn" title="Delete">🗑</button>
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
                    <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
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
                <label>Typical time per check-in, in minutes
                    <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Powers the Productivity Score and Time Allocation chart on Productivity Analysis. Just a rough estimate.</span></span>
                </label>
                <input type="number" name="est_minutes" min="1" max="240" value="20">
            </div>
            <div class="modal-close-row">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addGoalModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_goal" class="btn btn-primary">Save goal</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Goal Modal -->
<div class="modal-overlay" id="editGoalModal">
    <div class="modal-box">
        <h3>Edit goal</h3>
        <form method="POST">
            <input type="hidden" name="goal_id" id="edit_goal_id">
            <div class="field">
                <label>Goal title</label>
                <input type="text" name="title" id="edit_title" required>
            </div>
            <div class="field">
                <label>Description (optional)</label>
                <input type="text" name="description" id="edit_description">
            </div>
            <div class="field">
                <label>Category</label>
                <select name="category_id" id="edit_category_id" required>
                    <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="field">
                    <label>Frequency</label>
                    <select name="frequency" id="edit_frequency">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                    </select>
                </div>
                <div class="field">
                    <label>Target days / week</label>
                    <input type="number" name="target_per_week" id="edit_target_per_week" min="1" max="7">
                </div>
            </div>
            <div class="field">
                <label>Typical time per check-in (minutes)</label>
                <input type="number" name="est_minutes" id="edit_est_minutes" min="1" max="240">
            </div>
            <div class="modal-close-row">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('editGoalModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="edit_goal" class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditGoal(g) {
    document.getElementById('edit_goal_id').value = g.id;
    document.getElementById('edit_title').value = g.title;
    document.getElementById('edit_description').value = g.description || '';
    document.getElementById('edit_category_id').value = g.category_id;
    document.getElementById('edit_frequency').value = g.frequency;
    document.getElementById('edit_target_per_week').value = g.target_per_week;
    document.getElementById('edit_est_minutes').value = g.est_minutes;
    document.getElementById('editGoalModal').classList.add('show');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
