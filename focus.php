<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Focus Sessions';
$active = 'focus';

$stmt = $pdo->prepare("SELECT id, title FROM goals WHERE user_id=? AND is_active=1 ORDER BY title");
$stmt->execute([$uid]);
$active_goals = $stmt->fetchAll();

// Manual entry: log a session that already happened (not timed live) —
// e.g. catching up on a session you forgot to start the timer for.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_manual_session'])) {
    $goal_id = trim($_POST['goal_id'] ?? '') !== '' ? (int)$_POST['goal_id'] : null;
    if ($goal_id) {
        $check = $pdo->prepare("SELECT id FROM goals WHERE id=? AND user_id=?");
        $check->execute([$goal_id, $uid]);
        if (!$check->fetch()) $goal_id = null;
    }
    $minutes = max(1, min(600, (int)($_POST['actual_minutes'] ?? 0)));
    $status = ($_POST['status'] ?? 'completed') === 'interrupted' ? 'interrupted' : 'completed';
    $session_date = $_POST['session_date'] ?? date('Y-m-d');
    $session_time = $_POST['session_time'] ?? '09:00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date) || $session_date > date('Y-m-d')) $session_date = date('Y-m-d');
    if (!preg_match('/^\d{2}:\d{2}$/', $session_time)) $session_time = '09:00';
    $started_at = $session_date . ' ' . $session_time . ':00';
    $ended_at = date('Y-m-d H:i:s', strtotime($started_at) + $minutes * 60);
    $stmt = $pdo->prepare("INSERT INTO focus_sessions (user_id, goal_id, planned_minutes, actual_minutes, status, started_at, ended_at, note) VALUES (?,?,?,?,?,?,?,'Logged manually')");
    $stmt->execute([$uid, $goal_id, $minutes, $minutes, $status, $started_at, $ended_at]);
    header('Location: focus.php?logged=1');
    exit;
}

$stats = focus_session_stats($pdo, $uid, 7);
$recent = recent_focus_sessions($pdo, $uid, 8);

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['logged'])): ?><div class="alert alert-success">Session logged ⏱️</div><?php endif; ?>

<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Sessions this week</h4>
        <div class="stat-value"><span class="count-up" data-target="<?= $stats['sessions'] ?>">0</span></div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Avg. focus time</h4>
        <div class="stat-value"><span class="count-up" data-target="<?= $stats['avg_minutes'] ?>" data-suffix=" min">0 min</span></div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Success rate
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">% of this week's sessions marked "Complete" rather than stopped early.</span></span>
        </h4>
        <div class="stat-value"><span class="count-up" data-target="<?= $stats['success_rate'] ?>" data-suffix="%">0%</span></div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Total focus time</h4>
        <?php $th = intdiv($stats['total_minutes'], 60); $tm = $stats['total_minutes'] % 60; ?>
        <div class="stat-value"><?= $th ?>h <?= $tm ?>m</div>
        <div class="caption" style="margin-top:4px;">last 7 days</div>
    </div>
</div>

<div class="card anim-in" style="margin-bottom:24px; text-align:center; padding:40px 30px;">
    <div id="setupPanel">
        <h3 style="margin-bottom:18px;">⏱️ Start a focus session</h3>
        <div class="form-row" style="max-width:480px; margin:0 auto 18px;">
            <div class="field">
                <label>Linked goal (optional)</label>
                <select id="sessionGoal">
                    <option value="">No specific goal (deep work)</option>
                    <?php foreach ($active_goals as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Duration</label>
                <select id="sessionPreset" onchange="handlePresetChange()">
                    <option value="15">15 minutes</option>
                    <option value="25" selected>25 minutes</option>
                    <option value="45">45 minutes</option>
                    <option value="60">60 minutes</option>
                    <option value="custom">Custom…</option>
                </select>
            </div>
        </div>
        <input type="number" id="customMinutes" min="1" max="240" value="25" placeholder="Minutes" style="display:none; max-width:160px; margin:0 auto 18px; padding:12px 16px; border-radius:var(--radius-md); border:1.5px solid var(--lightgray); background:var(--offwhite); text-align:center;">
        <button class="btn btn-primary" onclick="startSession()">▶ Start focusing</button>
        <p style="margin-top:16px; font-size:12.5px; color:var(--ink-soft);">Already did the work and just forgot to time it? <a href="#" onclick="document.getElementById('manualModal').classList.add('show'); return false;">Log a past session</a> instead.</p>
    </div>

    <div id="timerPanel" style="display:none;">
        <div id="timerGoalLabel" style="font-size:13px; font-weight:700; color:var(--ink-soft); margin-bottom:8px;"></div>
        <div id="timerDisplay" class="timer-display">25:00</div>
        <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
            <button class="btn btn-ghost" id="pauseBtn" onclick="togglePause()">⏸ Pause</button>
            <button class="btn btn-primary" onclick="finishSession('completed')">✓ Complete</button>
            <button class="btn btn-danger" onclick="finishSession('interrupted')">✕ Stop early</button>
        </div>
    </div>
</div>
<div id="focusMsg" style="display:none; margin-bottom:18px;"></div>

<div class="section-title"><h2>Recent sessions</h2></div>
<?php if (empty($recent)): ?>
    <div class="card empty-state">
        <div class="em-ico">⏱️</div>
        <p>No focus sessions logged yet — start one above.</p>
    </div>
<?php else: ?>
<div class="card" style="padding:8px 26px;">
    <?php foreach ($recent as $s): ?>
    <div class="tracker-row">
        <span>
            <strong><?= $s['actual_minutes'] ?> min</strong>
            <span style="color:var(--ink-soft); font-weight:500;"> · <?= $s['goal_title'] ? htmlspecialchars($s['goal_title']) : 'Deep work' ?> · <?= date('d M, g:i a', strtotime($s['started_at'])) ?></span>
        </span>
        <span class="goal-tag <?= $s['status'] === 'completed' ? 'tag-good' : 'tag-attention' ?>" style="margin-left:auto;">
            <?= $s['status'] === 'completed' ? '✓ Completed' : '✕ Interrupted' ?>
        </span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Log a past session (manual entry — not timed live) -->
<div class="modal-overlay" id="manualModal">
    <div class="modal-box">
        <h3>Log a past session</h3>
        <form method="POST">
            <div class="field">
                <label>Linked goal (optional)</label>
                <select name="goal_id">
                    <option value="">No specific goal (deep work)</option>
                    <?php foreach ($active_goals as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="field">
                    <label>Date</label>
                    <input type="date" name="session_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="field">
                    <label>Time started</label>
                    <input type="time" name="session_time" value="09:00" required>
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label>Minutes spent</label>
                    <input type="number" name="actual_minutes" min="1" max="600" value="25" required>
                </div>
                <div class="field">
                    <label>Outcome</label>
                    <select name="status">
                        <option value="completed">Completed</option>
                        <option value="interrupted">Interrupted</option>
                    </select>
                </div>
            </div>
            <div class="modal-close-row">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('manualModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="log_manual_session" class="btn btn-primary">Save session</button>
            </div>
        </form>
    </div>
</div>

<script>
let timerInterval = null;
let remainingSeconds = 0;
let plannedMinutes = 25;
let startedAt = null;
let paused = false;

function handlePresetChange() {
    const preset = document.getElementById('sessionPreset').value;
    document.getElementById('customMinutes').style.display = preset === 'custom' ? 'block' : 'none';
}

function formatTime(totalSeconds) {
    const m = Math.floor(totalSeconds / 60);
    const s = totalSeconds % 60;
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}

function startSession() {
    const preset = document.getElementById('sessionPreset').value;
    plannedMinutes = preset === 'custom'
        ? Math.max(1, parseInt(document.getElementById('customMinutes').value || '25', 10))
        : parseInt(preset, 10);
    remainingSeconds = plannedMinutes * 60;
    startedAt = new Date();
    paused = false;

    const goalSelect = document.getElementById('sessionGoal');
    const goalLabel = goalSelect.options[goalSelect.selectedIndex].text;
    document.getElementById('timerGoalLabel').textContent = 'Focusing on: ' + goalLabel;
    document.getElementById('timerDisplay').textContent = formatTime(remainingSeconds);
    document.getElementById('setupPanel').style.display = 'none';
    document.getElementById('timerPanel').style.display = 'block';
    document.getElementById('pauseBtn').textContent = '⏸ Pause';

    timerInterval = setInterval(tick, 1000);
}

function tick() {
    if (paused) return;
    remainingSeconds--;
    document.getElementById('timerDisplay').textContent = formatTime(Math.max(0, remainingSeconds));
    if (remainingSeconds <= 0) {
        clearInterval(timerInterval);
        finishSession('completed');
    }
}

function togglePause() {
    paused = !paused;
    document.getElementById('pauseBtn').textContent = paused ? '▶ Resume' : '⏸ Pause';
}

async function finishSession(status) {
    clearInterval(timerInterval);
    const elapsedSeconds = (plannedMinutes * 60) - Math.max(0, remainingSeconds);
    const actualMinutes = Math.max(1, Math.round(elapsedSeconds / 60));
    const goalId = document.getElementById('sessionGoal').value || null;

    const msg = document.getElementById('focusMsg');
    msg.style.display = 'block';
    msg.innerHTML = '<div class="alert" style="background:var(--lightgray);">Saving your session...</div>';

    try {
        const res = await fetch('focus_log.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                goal_id: goalId || '',
                planned_minutes: plannedMinutes,
                actual_minutes: actualMinutes,
                status: status,
            })
        });
        const data = await res.json();
        if (data.success) {
            msg.innerHTML = '<div class="alert alert-success">Session logged — ' + actualMinutes + ' minute(s), ' + status + '. Reloading...</div>';
            setTimeout(() => location.reload(), 1000);
        } else {
            msg.innerHTML = '<div class="alert alert-error">Could not save that session. Try again.</div>';
        }
    } catch (e) {
        msg.innerHTML = '<div class="alert alert-error">Could not reach the server — session not saved.</div>';
    }

    document.getElementById('setupPanel').style.display = 'block';
    document.getElementById('timerPanel').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
