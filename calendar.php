<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Calendar View';
$active = 'calendar';

$year = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$data = month_calendar_data($pdo, $uid, $year, $month);
$days_in_month = $data['days_in_month'];
$checkins_by_date = $data['checkins_by_date'];
$mood_by_date = $data['mood_by_date'];
$active_goals = $data['active_goals'];
$max_possible = max(1, $active_goals);

$first_dow = (int)date('N', strtotime(sprintf('%04d-%02d-01', $year, $month))); // 1=Mon..7=Sun
$month_label = date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$today_str = date('Y-m-d');

$prev_m = $month - 1; $prev_y = $year; if ($prev_m < 1) { $prev_m = 12; $prev_y--; }
$next_m = $month + 1; $next_y = $year; if ($next_m > 12) { $next_m = 1; $next_y++; }

function cal_intensity_color($count, $max) {
    if ($count <= 0) return '#F8F8F8';
    $ratio = $count / $max;
    if ($ratio >= 1) return '#8FAE8B';
    if ($ratio > 0.6) return '#B9F0D3';
    if ($ratio > 0.3) return '#DDF5E5';
    return '#EEF9F1';
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.cal-grid{ display:grid; grid-template-columns: repeat(7, 1fr); gap:8px; }
.cal-dow{ font-size:11px; font-weight:800; text-transform:uppercase; color:var(--ink-soft); text-align:center; padding-bottom:4px; }
.cal-cell{ aspect-ratio:1; border-radius:12px; padding:8px; display:flex; flex-direction:column; justify-content:space-between; position:relative; }
.cal-cell.today{ outline:2.5px solid var(--ink); outline-offset:-2.5px; }
.cal-cell .cal-daynum{ font-size:12px; font-weight:800; }
.cal-cell .cal-mood{ font-size:16px; align-self:flex-end; }
.cal-cell .cal-count{ font-size:10px; font-weight:700; color:var(--ink-soft); }
.cal-cell.empty{ background:transparent; }
</style>

<div class="section-title">
    <h2><?= $month_label ?></h2>
    <div style="display:flex; gap:8px;">
        <a href="calendar.php?y=<?= $prev_y ?>&m=<?= $prev_m ?>" class="btn btn-ghost btn-sm">← Prev</a>
        <a href="calendar.php" class="btn btn-ghost btn-sm">Today</a>
        <a href="calendar.php?y=<?= $next_y ?>&m=<?= $next_m ?>" class="btn btn-ghost btn-sm">Next →</a>
    </div>
</div>

<div class="card anim-in">
    <div class="cal-grid" style="margin-bottom:10px;">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow): ?>
        <div class="cal-dow"><?= $dow ?></div>
        <?php endforeach; ?>
    </div>
    <div class="cal-grid">
        <?php for ($i = 1; $i < $first_dow; $i++): ?>
        <div class="cal-cell empty"></div>
        <?php endfor; ?>
        <?php for ($d = 1; $d <= $days_in_month; $d++):
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $count = $checkins_by_date[$date_str] ?? 0;
            $mood = $mood_by_date[$date_str] ?? null;
            $bg = cal_intensity_color($count, $max_possible);
            $is_today = $date_str === $today_str;
        ?>
        <div class="cal-cell <?= $is_today ? 'today' : '' ?>" style="background:<?= $bg ?>" title="<?= $date_str ?>: <?= $count ?> check-in<?= $count==1?'':'s' ?>">
            <span class="cal-daynum"><?= $d ?></span>
            <?php if ($mood): ?><span class="cal-mood"><?= MOOD_META[$mood]['emoji'] ?></span><?php endif; ?>
            <?php if ($count > 0): ?><span class="cal-count"><?= $count ?> ✓</span><?php endif; ?>
        </div>
        <?php endfor; ?>
    </div>
    <div class="heatmap-legend" style="margin-top:18px;">
        Fewer check-ins <div class="hm-cell" style="background:#F8F8F8"></div><div class="hm-cell" style="background:#EEF9F1"></div><div class="hm-cell" style="background:#DDF5E5"></div><div class="hm-cell" style="background:#B9F0D3"></div><div class="hm-cell" style="background:#8FAE8B"></div> More check-ins · 💛 = mood logged that day
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
