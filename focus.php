<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/narrative.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'Productivity';
$nav = 'productivity';

// ============================================================
// PRODUCTIVITY — the focus timer, your weekly rhythm, and where the
// Productivity Score is heading.
//
// Absorbs what used to be split between focus.php (the timer) and
// analyse.php's Productivity tab (weekday rhythm + score forecast).
//
// The manual-entry POST handler and the live timer's JavaScript are
// UNCHANGED — sessions are still saved through focus_log.php, which
// computes start and end times server-side rather than trusting the
// browser clock.
// ============================================================

$stmt = $pdo->prepare("SELECT id, title FROM goals WHERE user_id=? AND is_active=1 ORDER BY title");
$stmt->execute([$uid]);
$active_goals = $stmt->fetchAll();

// Manual entry: log a session that already happened.
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

$stats  = focus_session_stats($pdo, $uid, 7);
$recent = recent_focus_sessions($pdo, $uid, 8);
$wd     = weekday_productivity_scores($pdo, $uid, 8);
$links  = nar_links($pdo, $uid);   // correlations, already computed, now in plain words

// The Productivity Score forecast, phrased as plain language rather than
// as a model output. Same cached numbers the Analyse page reads.
$hab = get_forecast($pdo, $uid, 'habit');
$prod = null;
if ($hab && ($hab['status'] ?? '') === 'ok' && !empty($hab['history']['productivity_score'])) {
    $hist = $hab['history']['productivity_score'];
    $fc   = $hab['forecast']['productivity_score'];
    $now  = (float)end($hist);
    $nxt  = (float)($fc[0] ?? $now);
    $d    = round($nxt - $now, 1);
    $prod = [
        'history' => $hist, 'forecast' => $fc,
        'lower' => $hab['forecast']['productivity_score_lower'] ?? [],
        'upper' => $hab['forecast']['productivity_score_upper'] ?? [],
        'now' => $now, 'next' => $nxt,
        'claim' => $d > 2 ? "You're building toward about " . round($nxt) . " out of 100 next week."
                 : ($d < -2 ? "Next week looks like easing to about " . round($nxt) . " out of 100."
                            : "You're holding steady around " . round($nxt) . " out of 100."),
        'take'  => $d > 2 ? "That's " . abs($d) . " points up on this week. Whatever you changed recently, it's working."
                 : ($d < -2 ? "About " . abs($d) . " points down. Usually it's one shorter week rather than a real slide."
                            : "Your working rhythm has settled into something repeatable."),
    ];
}

$hours_week = round($stats['total_minutes'] / 60, 1);
$best_day = $wd[0] ?? null;

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">Productivity</p>
            <h1><?php if ($stats['sessions'] === 0): ?>
                Nothing timed this week yet.
            <?php else: ?>
                <em><?= $hours_week ?></em> hours of real focus this week.
            <?php endif; ?></h1>
            <p class="hero-sub"><?php if ($stats['sessions'] === 0): ?>
                Start a session below. Timed work counts for more than an estimate, because it actually happened.
            <?php else: ?>
                Across <?= nar_plural($stats['sessions'], 'session') ?><?= $best_day ? ', and ' . $best_day['dname'] . ' is where you do your best work' : '' ?>.
            <?php endif; ?></p>
        </div>
        <div class="hero-stats">
            <div>
                <div class="hstat-n"><span data-count="<?= $stats['sessions'] ?>"><?= $stats['sessions'] ?></span></div>
                <div class="hstat-l">sessions this week</div>
            </div>
            <div>
                <div class="hstat-n"><span data-count="<?= $stats['avg_minutes'] ?>" data-dp="0"><?= round($stats['avg_minutes']) ?></span><span class="u">min</span></div>
                <div class="hstat-l">typical length</div>
            </div>
            <?php if ($stats['sessions'] > 0): ?>
            <div>
                <div class="hstat-n"><span data-count="<?= $stats['success_rate'] ?>" data-post="%"><?= $stats['success_rate'] ?>%</span></div>
                <div class="hstat-l">seen through to the end</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="wrap">

<?php if (isset($_GET['logged'])): ?><div class="flash" style="margin-top:34px;">Session logged.</div><?php endif; ?>

<!-- ---------- The timer ---------- -->
<section class="ch enter">
    <div class="ch-head"><h2>Focus</h2></div>
    <p class="ch-lead">Pick a length, start it, and leave it running. Stopping early still counts — the time you did is the time that's saved.</p>

    <div class="timer">
        <div id="setupPanel">
            <h3>How long are you going for?</h3>
            <div class="timer-setup">
                <div class="field">
                    <label for="sessionGoal">On what (optional)</label>
                    <select id="sessionGoal">
                        <option value="">Deep work — nothing specific</option>
                        <?php foreach ($active_goals as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="sessionPreset">For how long</label>
                    <select id="sessionPreset" onchange="handlePresetChange()">
                        <option value="15">15 minutes</option>
                        <option value="25" selected>25 minutes</option>
                        <option value="45">45 minutes</option>
                        <option value="60">An hour</option>
                        <option value="custom">Something else</option>
                    </select>
                </div>
            </div>
            <input type="number" id="customMinutes" min="1" max="240" value="25" placeholder="Minutes"
                   aria-label="Custom length in minutes"
                   style="display:none; max-width:170px; margin:0 auto 20px; text-align:center;
                          background:rgba(255,255,255,.07); border:1.5px solid rgba(255,255,255,.16);
                          color:var(--on-dark); border-radius:10px; padding:12px 16px; font-family:var(--ui); font-size:15px;">
            <button class="btn btn-dark" onclick="startSession()">Start focusing</button>
            <p class="timer-hint">Did the work and forgot to time it?
                <a onclick="document.getElementById('manualSheet').classList.add('show')">Log it after the fact</a>.</p>
        </div>

        <div id="timerPanel" style="display:none;">
            <div class="timer-on" id="timerGoalLabel"></div>
            <div class="clock" id="timerDisplay">25:00</div>
            <div class="timer-btns">
                <button class="btn btn-line" id="pauseBtn" onclick="togglePause()"
                        style="border-color:rgba(255,255,255,.22); color:var(--on-dark);">Pause</button>
                <button class="btn btn-dark" onclick="finishSession('completed')">Done</button>
                <button class="btn btn-line" onclick="finishSession('interrupted')"
                        style="border-color:rgba(255,255,255,.22); color:var(--on-dark);">Stop early</button>
            </div>
        </div>
    </div>
    <div id="focusMsg" style="display:none; margin-top:16px;"></div>
</section>

<!-- ---------- Rhythm ---------- -->
<?php if (count($wd) >= 3): ?>
<section class="ch enter">
    <div class="ch-head"><h2>Your week has a shape</h2></div>
    <p class="ch-lead">
        Averaged over the last eight weeks. <?= htmlspecialchars($wd[0]['dname']) ?> is consistently your strongest day —
        worth putting the work you're dreading there.
    </p>

    <?php
    $order = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $by = [];
    foreach ($wd as $d) $by[$d['dname']] = $d['score'];
    ?>
    <div class="days">
        <?php foreach ($order as $d): $v = $by[$d] ?? 0; ?>
        <div class="day <?= $d === $wd[0]['dname'] ? 'best' : '' ?>">
            <div class="day-l"><?= substr($d, 0, 3) ?></div>
            <div class="day-v"><?= $v ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ---------- What's next ---------- -->
<?php if ($prod):
    $labels = [];
    foreach ($prod['history'] as $i => $_) $labels[] = 'Wk ' . ($i + 1);
    foreach ($prod['forecast'] as $i => $_) $labels[] = 'Next ' . ($i + 1);
?>
<section class="ch enter">
    <div class="ch-head"><h2>What's likely next</h2></div>
    <p class="ch-lead">Your working weeks, scored out of 100 — a blend of how often you showed up and how long you stayed.</p>

    <article class="viz">
        <div class="viz-top">
            <h3 class="viz-claim"><?= htmlspecialchars($prod['claim']) ?></h3>
            <p class="viz-why">Where the last few weeks point, if your routine holds.</p>
        </div>
        <div class="viz-body">
            <div class="chart-scroll"><?= nar_chart($labels, [
                'How your weeks score' => [
                    'actual' => $prod['history'], 'forecast' => $prod['forecast'],
                    'color' => '#4A9A58',
                    'ci_lower' => $prod['lower'], 'ci_upper' => $prod['upper'],
                ],
            ], 640, 220) ?></div>
        </div>
        <div style="padding:0 30px;"><div class="viz-foot">
            <svg class="viz-foot-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 21V9.5M12 9.5C12 6 9.5 3.5 5.5 3.5c0 4 2.5 6 6.5 6Z"/>
            </svg>
            <p class="viz-foot-t"><b>What this means.</b> <?= htmlspecialchars($prod['take']) ?></p>
        </div></div>
    </article>

    <p style="margin-top:20px;"><a class="more" href="simulate.php">See what another half hour a day would do</a></p>
</section>
<?php endif; ?>

<!-- ---------- What moves with what ---------- -->
<?php if ($links || nar_links_checked() > 0): ?>
<section class="ch enter">
    <div class="ch-head"><h2>Things that move together</h2></div>
    <p class="ch-lead">
        Patterns Sprout noticed across your own days and weeks. Each dot is one of them — so you can see the
        pattern rather than take our word for it.
    </p>

    <?php if (!$links): ?>
    <div class="panel" style="padding:30px 32px;">
        <h3 style="font-size:19px;">Nothing stands out yet.</h3>
        <p style="color:var(--ink-mid); font-size:14.5px; margin-top:10px; max-width:58ch;">
            Sprout compared your sleep and mood against how much you got done, and your focused time against your
            check-ins. Neither pair moved together strongly enough to be worth calling a pattern — which is a real
            answer, not a missing one. Weak links are left out on purpose, so the ones that do appear here mean something.
        </p>
    </div>
    <?php else: ?>
    <div class="grid" style="gap:20px;">
    <?php foreach ($links as $l): ?>
        <article class="viz">
            <div class="viz-top">
                <h3 class="viz-claim"><?= htmlspecialchars($l['claim']) ?></h3>
                <p class="viz-why"><?= htmlspecialchars($l['why']) ?></p>
            </div>
            <div class="viz-body">
                <div class="chart-scroll"><?= svg_scatter_chart($l['points'], $l['x'], $l['y'], '#4A9A58', 600, 240) ?></div>
            </div>
            <div style="padding:0 30px;"><div class="viz-foot">
                <svg class="viz-foot-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 21V9.5M12 9.5C12 6 9.5 3.5 5.5 3.5c0 4 2.5 6 6.5 6Z"/>
                </svg>
                <p class="viz-foot-t">
                    <b>What this means.</b> <?= htmlspecialchars($l['take']) ?><br>
                    <b>Try this.</b> <?= htmlspecialchars($l['try']) ?>
                </p>
            </div></div>
        </article>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- ---------- Recent ---------- -->
<section class="ch enter">
    <div class="ch-head"><h2>Recent sessions</h2></div>
    <?php if (empty($recent)): ?>
        <div class="panel" style="text-align:center; padding:44px 24px;">
            <h3 style="font-size:20px;">Nothing here yet.</h3>
            <p style="color:var(--ink-mid); font-size:14.5px; margin-top:10px;">Your first session will show up here the moment you finish it.</p>
        </div>
    <?php else: ?>
    <p class="ch-lead">The last <?= count($recent) ?>, most recent first.</p>
    <div class="panel">
        <div class="sessions">
            <?php foreach ($recent as $s): ?>
            <div class="sess">
                <span class="sess-m"><?= $s['actual_minutes'] ?><span style="font-size:12px; color:var(--ink-soft); font-family:var(--ui); font-weight:500;">m</span></span>
                <div>
                    <div class="sess-t"><?= $s['goal_title'] ? htmlspecialchars($s['goal_title']) : 'Deep work' ?></div>
                    <div class="sess-d"><?= date('j M, g:i a', strtotime($s['started_at'])) ?></div>
                </div>
                <span class="tag <?= $s['status'] === 'completed' ? 'ok' : 'late' ?>">
                    <?= $s['status'] === 'completed' ? 'Finished' : 'Stopped early' ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>

</div><!-- /wrap -->

<!-- ---------- Log a past session ---------- -->
<div class="veil" id="manualSheet">
    <div class="sheet">
        <h2>Log a past session</h2>
        <p class="note">For work you did without the timer running. It counts the same.</p>
        <form method="POST">
            <div class="field">
                <label for="m-goal">On what (optional)</label>
                <select id="m-goal" name="goal_id">
                    <option value="">Deep work — nothing specific</option>
                    <?php foreach ($active_goals as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row2">
                <div class="field">
                    <label for="m-mins">How many minutes</label>
                    <input id="m-mins" type="number" name="actual_minutes" min="1" max="600" value="25" required>
                </div>
                <div class="field">
                    <label for="m-status">How did it go</label>
                    <select id="m-status" name="status">
                        <option value="completed">Finished it</option>
                        <option value="interrupted">Stopped early</option>
                    </select>
                </div>
            </div>
            <div class="row2">
                <div class="field">
                    <label for="m-date">Which day</label>
                    <input id="m-date" type="date" name="session_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="field">
                    <label for="m-time">Roughly when</label>
                    <input id="m-time" type="time" name="session_time" value="09:00" required>
                </div>
            </div>
            <div class="sheet-foot">
                <button type="button" class="btn btn-line" onclick="document.getElementById('manualSheet').classList.remove('show')">Cancel</button>
                <button type="submit" name="log_manual_session" class="btn btn-go">Log it</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ------------------------------------------------------------------
   The timer. This logic is unchanged from the previous version — the
   session is still saved through focus_log.php, which works out the
   start and end times on the server rather than trusting this clock.
   ------------------------------------------------------------------ */
let timerInterval = null;
let remainingSeconds = 0;
let plannedMinutes = 25;
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
    paused = false;

    const goalSelect = document.getElementById('sessionGoal');
    const goalLabel = goalSelect.options[goalSelect.selectedIndex].text;
    document.getElementById('timerGoalLabel').textContent = goalLabel;
    document.getElementById('timerDisplay').textContent = formatTime(remainingSeconds);
    document.getElementById('timerDisplay').classList.remove('paused');
    document.getElementById('setupPanel').style.display = 'none';
    document.getElementById('timerPanel').style.display = 'block';
    document.getElementById('pauseBtn').textContent = 'Pause';

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
    document.getElementById('pauseBtn').textContent = paused ? 'Resume' : 'Pause';
    document.getElementById('timerDisplay').classList.toggle('paused', paused);
}

async function finishSession(status) {
    clearInterval(timerInterval);
    const elapsedSeconds = (plannedMinutes * 60) - Math.max(0, remainingSeconds);
    const actualMinutes = Math.max(1, Math.round(elapsedSeconds / 60));
    const goalId = document.getElementById('sessionGoal').value || null;

    const msg = document.getElementById('focusMsg');
    msg.style.display = 'block';
    msg.innerHTML = '<div class="flash">Saving your session…</div>';

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
            msg.innerHTML = '<div class="flash">' + actualMinutes + ' minutes logged. One moment…</div>';
            setTimeout(() => location.reload(), 900);
        } else {
            msg.innerHTML = '<div class="flash" style="background:var(--clay);">That session did not save. Try again.</div>';
        }
    } catch (e) {
        msg.innerHTML = '<div class="flash" style="background:var(--clay);">Could not reach the server, so that session was not saved.</div>';
    }

    document.getElementById('setupPanel').style.display = 'block';
    document.getElementById('timerPanel').style.display = 'none';
}

document.querySelectorAll('.veil').forEach(v => {
    v.addEventListener('click', e => { if (e.target === v) v.classList.remove('show'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.veil.show').forEach(v => v.classList.remove('show'));
});
</script>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
