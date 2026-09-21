<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/narrative.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'Reminders';
$nav = 'habits';   // reminders exist to protect habits, so that section stays lit

// ============================================================
// REMINDERS — nudges that live inside Sprout.
//
// All three POST handlers are UNCHANGED. Worth being straight about what
// these are: they appear in the app, and nothing is emailed or pushed.
// The page says so plainly rather than letting someone rely on a
// notification that never arrives.
// ============================================================

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
$today_short = date('D');
$on_today = array_filter($reminders, fn($r) => $r['is_active'] && str_contains($r['days_of_week'], $today_short));

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">Reminders</p>
            <h1><?= empty($reminders)
                ? 'A nudge at the right moment.'
                : nar_plural(count($on_today), 'nudge') . ' set for today.' ?></h1>
            <p class="hero-sub">
                These appear inside Sprout — on your Overview and here. Nothing is emailed or pushed to your phone,
                so treat them as a note to yourself rather than an alarm.
            </p>
        </div>
    </div>
</header>

<div class="wrap">

<?php if (isset($_GET['added'])): ?><div class="flash" style="margin-top:34px;">Reminder set.</div><?php endif; ?>

<section class="ch enter" style="padding-top:<?= isset($_GET['added']) ? '28px' : '44px' ?>;">
    <div class="ch-head" style="justify-content:space-between; width:100%;">
        <h2>Your reminders</h2>
        <button class="btn btn-go" onclick="document.getElementById('remSheet').classList.add('show')">Add a reminder</button>
    </div>

    <?php if (empty($reminders)): ?>
        <div class="panel" style="text-align:center; padding:48px 24px;">
            <h3 style="font-size:21px;">Nothing set yet.</h3>
            <p style="color:var(--ink-mid); font-size:14.5px; margin:10px auto 22px; max-width:44ch;">
                Most habits fail at the moment you forget, not the moment you decide. A reminder at the right
                hour closes most of that gap.
            </p>
            <button class="btn btn-go" onclick="document.getElementById('remSheet').classList.add('show')">Add a reminder</button>
        </div>
    <?php else: ?>
    <p class="ch-lead">Switch one off to keep it without being nudged.</p>
    <div class="rems">
        <?php foreach ($reminders as $r): ?>
        <article class="rem <?= $r['is_active'] ? '' : 'off' ?>">
            <div class="rem-t"><?= date('g:i', strtotime($r['remind_time'])) ?><span style="font-size:12px; font-family:var(--ui); font-weight:500; color:var(--ink-soft);"><?= date('a', strtotime($r['remind_time'])) ?></span></div>
            <div>
                <div class="rem-n"><?= htmlspecialchars($r['title']) ?></div>
                <div class="rem-d">
                    <?= $r['days_of_week'] === implode(',', $all_days) ? 'Every day' : htmlspecialchars(str_replace(',', ' · ', $r['days_of_week'])) ?>
                    <?= $r['goal_title'] ? ' — ' . htmlspecialchars($r['goal_title']) : '' ?>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="reminder_id" value="<?= $r['id'] ?>">
                <button type="submit" name="toggle_reminder" class="switch <?= $r['is_active'] ? 'on' : '' ?>"
                        aria-label="<?= $r['is_active'] ? 'Turn off' : 'Turn on' ?> <?= htmlspecialchars($r['title']) ?>"></button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this reminder?');">
                <input type="hidden" name="reminder_id" value="<?= $r['id'] ?>">
                <button type="submit" name="delete_reminder" class="kill" style="opacity:1;" aria-label="Delete reminder">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"/>
                    </svg>
                </button>
            </form>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

</div>

<div class="veil" id="remSheet">
    <div class="sheet">
        <h2>A new reminder</h2>
        <p class="note">Pick the moment you're most likely to forget.</p>
        <form method="POST">
            <div class="field">
                <label for="r-title">What should it say?</label>
                <input id="r-title" type="text" name="title" placeholder="Time to read" required>
            </div>
            <div class="field">
                <label for="r-goal">About which habit (optional)</label>
                <select id="r-goal" name="goal_id">
                    <option value="">Nothing in particular</option>
                    <?php foreach ($active_goals as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="r-time">At what time</label>
                <input id="r-time" type="time" name="remind_time" value="09:00" required>
            </div>
            <div class="field">
                <label>On which days</label>
                <div class="chips" style="margin-bottom:0;">
                    <?php foreach ($all_days as $d): ?>
                    <label class="chip" style="cursor:pointer;">
                        <input type="checkbox" name="days[]" value="<?= $d ?>" checked
                               style="width:auto; margin-right:7px; accent-color:var(--shoot-deep);">
                        <?= $d ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="sheet-foot">
                <button type="button" class="btn btn-line" onclick="document.getElementById('remSheet').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_reminder" class="btn btn-go">Set reminder</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.veil').forEach(v => {
    v.addEventListener('click', e => { if (e.target === v) v.classList.remove('show'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.veil.show').forEach(v => v.classList.remove('show'));
});
</script>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
