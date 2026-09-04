<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Mood Tracker';
$active = 'mood';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mood'])) {
    $mood = $_POST['mood'] ?? '';
    $sleep = $_POST['sleep_hours'] !== '' ? (float)$_POST['sleep_hours'] : null;
    $stress = in_array($_POST['stress_level'] ?? '', ['low','medium','high']) ? $_POST['stress_level'] : null;
    $note = trim($_POST['note'] ?? '');
    if (array_key_exists($mood, MOOD_META)) {
        $stmt = $pdo->prepare("INSERT INTO mood_logs (user_id, log_date, mood, sleep_hours, stress_level, note)
            VALUES (?, CURDATE(), ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE mood=VALUES(mood), sleep_hours=VALUES(sleep_hours), stress_level=VALUES(stress_level), note=VALUES(note)");
        $stmt->execute([$uid, $mood, $sleep, $stress, $note]);
        evaluate_achievements($pdo, $uid);
    }
    header('Location: mood.php?saved=1&range=' . urlencode($_GET['range'] ?? 'month'));
    exit;
}

$today_mood = todays_mood($pdo, $uid);
$score = wellness_score($pdo, $uid);
$streak = mood_streak_days($pdo, $uid);

// ---- Stats view toggle: week / month / all ----
$range = $_GET['range'] ?? 'month';
if (!in_array($range, ['week', 'month', 'all'])) $range = 'month';
$cal_span_days = ['week' => 7, 'month' => 28, 'all' => 90][$range];
$stats_span_days = ['week' => 7, 'month' => 30, 'all' => 3650][$range];

$stmt = $pdo->prepare("SELECT log_date, mood FROM mood_logs WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
$stmt->execute([$uid, $cal_span_days - 1]);
$mood_by_date = [];
foreach ($stmt->fetchAll() as $r) $mood_by_date[$r['log_date']] = $r['mood'];

$cal_days = [];
$cursor = new DateTime('-' . ($cal_span_days - 1) . ' days');
for ($i = 0; $i < $cal_span_days; $i++) { $cal_days[] = $cursor->format('Y-m-d'); $cursor->modify('+1 day'); }

$stmt = $pdo->prepare("SELECT log_date, sleep_hours, stress_level FROM mood_logs WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY log_date");
$stmt->execute([$uid, $stats_span_days - 1]);
$span_rows = $stmt->fetchAll();
$avg_sleep = 0; $sleep_n = 0; $high_stress_days = 0; $days_logged = count($span_rows);
$mood_counts = [];
foreach ($span_rows as $r) {
    if ($r['sleep_hours'] !== null) { $avg_sleep += (float)$r['sleep_hours']; $sleep_n++; }
    if ($r['stress_level'] === 'high') $high_stress_days++;
}
$avg_sleep = $sleep_n > 0 ? round($avg_sleep / $sleep_n, 1) : null;

// mood distribution over the selected range (for the "all time" / "month" view)
$stmt = $pdo->prepare("SELECT mood, COUNT(*) c FROM mood_logs WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY mood");
$stmt->execute([$uid, $stats_span_days - 1]);
foreach ($stmt->fetchAll() as $r) $mood_counts[$r['mood']] = (int)$r['c'];
$mood_total = array_sum($mood_counts);

require_once __DIR__ . '/includes/header.php';
$range_labels = ['week' => 'Last 7 days', 'month' => 'Last 30 days', 'all' => 'All time'];
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Today's check-in saved 💛</div><?php endif; ?>

<div class="bento-grid">
    <div class="bento-cell span-2">
        <h4>How are you feeling today?</h4>
        <form method="POST" id="moodForm">
            <div class="mood-picker">
                <?php foreach (MOOD_META as $key => $m): ?>
                <label class="mood-opt <?= ($today_mood && $today_mood['mood'] === $key) ? 'selected' : '' ?>" data-mood="<?= $key ?>">
                    <input type="radio" name="mood" value="<?= $key ?>" style="display:none" <?= ($today_mood && $today_mood['mood'] === $key) ? 'checked' : '' ?> required>
                    <span class="m-emoji"><?= $m['emoji'] ?></span>
                    <span class="m-label"><?= $m['label'] ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="form-row" style="margin-top:18px;">
                <div class="field">
                    <label>Sleep last night (hours)</label>
                    <input type="number" step="0.5" min="0" max="14" name="sleep_hours" value="<?= htmlspecialchars($today_mood['sleep_hours'] ?? '') ?>" placeholder="e.g. 7.5">
                </div>
                <div class="field">
                    <label>Stress level</label>
                    <select name="stress_level">
                        <option value="">—</option>
                        <option value="low" <?= (($today_mood['stress_level'] ?? '') === 'low') ? 'selected' : '' ?>>Low</option>
                        <option value="medium" <?= (($today_mood['stress_level'] ?? '') === 'medium') ? 'selected' : '' ?>>Medium</option>
                        <option value="high" <?= (($today_mood['stress_level'] ?? '') === 'high') ? 'selected' : '' ?>>High</option>
                    </select>
                </div>
            </div>
            <div class="field" style="margin-top:14px;">
                <label>Note (optional)</label>
                <input type="text" name="note" placeholder="Anything on your mind?" value="<?= htmlspecialchars($today_mood['note'] ?? '') ?>">
            </div>
            <button type="submit" name="save_mood" class="btn btn-primary" style="margin-top:16px;">Save today's check-in</button>
        </form>
    </div>

    <div class="bento-cell">
        <h4>Wellness score</h4>
        <div class="big-ring">
            <svg width="130" height="130" viewBox="0 0 130 130">
                <circle class="ring-bg" cx="65" cy="65" r="52"></circle>
                <?php $c = 2*M_PI*52; $pct = $score ?? 0; $off = $c - ($pct/100)*$c; ?>
                <circle class="ring-fg" cx="65" cy="65" r="52" stroke-dasharray="<?= $c ?>" stroke-dashoffset="<?= $off ?>"></circle>
            </svg>
            <div class="ring-center">
                <div class="big-num"><?= $score !== null ? $score : '—' ?></div>
                <div class="big-label">out of 100</div>
            </div>
        </div>
        <p style="text-align:center; font-size:12.5px; color:var(--ink-soft); font-weight:600; margin-top:6px;">
            <?= $score === null ? 'Log a few days to see your score' : 'based on the last 7 days' ?>
        </p>
    </div>

    <div class="bento-cell">
        <h4>Mood check-in streak</h4>
        <div class="big-num" style="font-size:40px; font-family:'Nunito',sans-serif; font-weight:800;">🔥 <?= $streak ?></div>
        <p style="font-size:12.5px; color:var(--ink-soft); font-weight:600; margin-top:6px;">days in a row</p>
    </div>
</div>

<div class="section-title">
    <h2>Stats</h2>
</div>
<div class="seg-tabs">
    <button class="<?= $range === 'week' ? 'active' : '' ?>" onclick="location.href='mood.php?range=week'">Week</button>
    <button class="<?= $range === 'month' ? 'active' : '' ?>" onclick="location.href='mood.php?range=month'">Month</button>
    <button class="<?= $range === 'all' ? 'active' : '' ?>" onclick="location.href='mood.php?range=all'">All time</button>
</div>

<div class="bento-grid">
    <div class="bento-cell span-2 anim-in">
        <h4>Mood calendar · <?= strtolower($range_labels[$range]) ?></h4>
        <div class="mood-cal" style="<?= $range === 'all' ? 'grid-template-columns: repeat(15, 1fr);' : '' ?>">
            <?php foreach ($cal_days as $d):
                $m = $mood_by_date[$d] ?? null;
                $bg = $m ? MOOD_META[$m]['color'] : '#EFEFEF';
                $emo = $m ? MOOD_META[$m]['emoji'] : '';
            ?>
            <div class="mc-day" style="background:<?= $bg ?>" title="<?= $d ?>"><?= $emo ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bento-cell span-2 anim-in">
        <h4>Sleep, stress &amp; mood — <?= strtolower($range_labels[$range]) ?></h4>
        <div class="tracker-row">
            <span>😴 Avg. sleep</span>
            <div class="tracker-bar"><span class="grow-in" style="width:<?= $avg_sleep ? min(100, ($avg_sleep/9)*100) : 0 ?>%"></span></div>
            <strong><?= $avg_sleep ?? '—' ?>h</strong>
        </div>
        <div class="tracker-row">
            <span>😣 High-stress days</span>
            <div class="tracker-bar"><span class="grow-in" style="width:<?= $days_logged > 0 ? ($high_stress_days/$days_logged)*100 : 0 ?>%; background:var(--pink-deep);"></span></div>
            <strong><?= $high_stress_days ?>/<?= $days_logged ?></strong>
        </div>
        <?php if ($mood_total === 0): ?>
            <p class="empty-state" style="padding:20px 0;"><span class="em-ico">💛</span><br>Check in to build your trends.</p>
        <?php else: ?>
            <?php foreach (MOOD_META as $key => $m): $mc = $mood_counts[$key] ?? 0; if ($mc === 0) continue; $mp = round(($mc / $mood_total) * 100); ?>
            <div class="tracker-row">
                <span><?= $m['emoji'] ?> <?= $m['label'] ?></span>
                <div class="tracker-bar"><span class="grow-in" style="width:<?= $mp ?>%; background:<?= $m['color'] ?>;"></span></div>
                <strong><?= $mp ?>%</strong>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.mood-opt').forEach(opt => {
    opt.addEventListener('click', () => {
        document.querySelectorAll('.mood-opt').forEach(o => o.classList.remove('selected'));
        opt.classList.add('selected');
        opt.querySelector('input').checked = true;
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
