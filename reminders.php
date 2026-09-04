<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Reminders';
$active = 'reminders';

$stmt = $pdo->prepare("SELECT id, title FROM goals WHERE user_id=? AND is_active=1 ORDER BY title");
$stmt->execute([$uid]);
$active_goals = $stmt->fetchAll();

$all_days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reminder'])) {
    $title = trim($_POST['title'] ?? '');
    $goal_id = trim($_POST['goal_id'] ?? '') !== '' ? (int)$_POST['goal_id'] : null;
    $time = $_POST['remind_time'] ?? '09:00';
    $days = array_intersect((array)($_POST['days'] ?? []), $all_days);
    $days_str = !empty($days) ? implode(',', $days) : implode(',', $all_days);
    if ($title !== '' && preg_match('/^\d{2}:\d{2}$/', $time)) {
        $stmt = $pdo->prepare("INSERT INTO reminders (user_id, goal_id, title, remind_time, days_of_week) VALUES (?,?,?,?,?)");
        $stmt->execute([$uid, $goal_id, $title, $time, $days_str]);
    }
    header('Location: reminders.php?added=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_reminder'])) {
    $stmt = $pdo->prepare("UPDATE reminders SET is_active = 1 - is_active WHERE id=? AND user_id=?");
    $stmt->execute([(int)$_POST['reminder_id'], $uid]);
    header('Location: reminders.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_reminder'])) {
    $stmt = $pdo->prepare("DELETE FROM reminders WHERE id=? AND user_id=?");
    $stmt->execute([(int)$_POST['reminder_id'], $uid]);
    header('Location: reminders.php');
    exit;
}

$reminders = all_reminders($pdo, $uid);

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success">Reminder added 🔔</div><?php endif; ?>

<div class="card anim-in" style="background: var(--offwhite); box-shadow:none; border:1.5px dashed var(--lightgray); margin-bottom:22px;">
    <p style="font-size:13px; font-weight:600; color:var(--ink-soft);">🔔 Reminders show up here and as a "Today" list on your Dashboard — Sprout doesn't send emails, texts or push notifications yet, so keep this tab open (or check your Dashboard) to see them.</p>
</div>

<div class="section-title">
    <h2>Your reminders</h2>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('addReminderModal').classList.add('show')">+ Add reminder</button>
</div>

<?php if (empty($reminders)): ?>
    <div class="card empty-state">
        <div class="em-ico">🔔</div>
        <p>No reminders yet. Add one above.</p>
    </div>
<?php else: ?>
<div class="card" style="padding:8px 26px;">
    <?php foreach ($reminders as $r): ?>
    <div class="tracker-row">
        <span>
            <strong><?= htmlspecialchars($r['title']) ?></strong>
            <span style="color:var(--ink-soft); font-weight:500;"> · <?= date('g:i a', strtotime($r['remind_time'])) ?> · <?= htmlspecialchars($r['days_of_week']) ?><?= $r['goal_title'] ? ' · ' . htmlspecialchars($r['goal_title']) : '' ?></span>
        </span>
        <span class="goal-tag" style="margin-left:auto; margin-right:10px; <?= $r['is_active'] ? 'background:#2F523322;color:#2F5233' : 'background:var(--lightgray);color:var(--ink-soft)' ?>">
            <?= $r['is_active'] ? 'Active' : 'Paused' ?>
        </span>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="reminder_id" value="<?= $r['id'] ?>">
            <button type="submit" name="toggle_reminder" class="icon-btn" title="<?= $r['is_active'] ? 'Pause' : 'Resume' ?>"><?= $r['is_active'] ? '⏸' : '▶' ?></button>
        </form>
        <form method="POST" onsubmit="return confirm('Delete this reminder?');" style="display:inline;">
            <input type="hidden" name="reminder_id" value="<?= $r['id'] ?>">
            <button type="submit" name="delete_reminder" class="icon-btn" title="Delete">🗑</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal-overlay" id="addReminderModal">
    <div class="modal-box">
        <h3>New reminder</h3>
        <form method="POST">
            <div class="field">
                <label>Title</label>
                <input type="text" name="title" placeholder="e.g. Evening reading" required>
            </div>
            <div class="field">
                <label>Linked goal (optional)</label>
                <select name="goal_id">
                    <option value="">None</option>
                    <?php foreach ($active_goals as $g): ?><option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['title']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Time</label>
                <input type="time" name="remind_time" value="09:00" required>
            </div>
            <div class="field">
                <label>Days</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php foreach ($all_days as $d): ?>
                    <label style="display:flex; align-items:center; gap:5px; font-size:12.5px; font-weight:600; background:var(--offwhite); padding:6px 10px; border-radius:999px;">
                        <input type="checkbox" name="days[]" value="<?= $d ?>" checked style="margin:0;"> <?= $d ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-close-row">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addReminderModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_reminder" class="btn btn-primary">Save reminder</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
