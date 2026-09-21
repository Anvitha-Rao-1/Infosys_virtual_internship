<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/narrative.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'Calendar';
$nav = 'habits';   // the calendar is a view of your habits, so that section stays lit

// ============================================================
// CALENDAR — a month at a time.
//
// Data comes from month_calendar_data() exactly as before; only the
// presentation changed. The greener a day, the more you got done on it.
// ============================================================

$year = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$data = month_calendar_data($pdo, $uid, $year, $month);
$days_in_month    = $data['days_in_month'];
$checkins_by_date = $data['checkins_by_date'];
$mood_by_date     = $data['mood_by_date'];
$active_goals     = $data['active_goals'];
$max_possible     = max(1, $active_goals);

$first_dow   = (int)date('N', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$month_label = date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$today_str   = date('Y-m-d');

$prev_m = $month - 1; $prev_y = $year; if ($prev_m < 1) { $prev_m = 12; $prev_y--; }
$next_m = $month + 1; $next_y = $year; if ($next_m > 12) { $next_m = 1; $next_y++; }

/** Warm cream through to deep shoot green as the day fills up. */
function cal_shade(int $count, int $max): string {
    if ($count <= 0) return 'var(--paper)';
    $r = $count / $max;
    if ($r >= 1)   return 'var(--shoot)';
    if ($r > 0.6)  return '#9BD39E';
    if ($r > 0.3)  return '#C4E7C6';
    return '#E3F2E4';
}

// A month only means something next to another month.
$done_this_month = array_sum($checkins_by_date);
$days_with_any   = count(array_filter($checkins_by_date));
$perfect_days    = count(array_filter($checkins_by_date, fn($c) => $c >= $max_possible));

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">Calendar</p>
            <h1><?php if ($done_this_month === 0): ?>
                Nothing logged in <?= date('F', strtotime($month_label)) ?> yet.
            <?php else: ?>
                You showed up on <em><?= $days_with_any ?></em> days this month.
            <?php endif; ?></h1>
            <p class="hero-sub">
                <?= $done_this_month === 0
                    ? 'Check in on the Habits page and the days start filling in here.'
                    : 'The greener a day, the more of your list you finished. Gaps are just gaps — nobody has a full grid.' ?>
            </p>
        </div>
        <?php if ($done_this_month > 0): ?>
        <div class="hero-stats">
            <div>
                <div class="hstat-n"><span data-count="<?= $done_this_month ?>"><?= $done_this_month ?></span></div>
                <div class="hstat-l">check-ins this month</div>
            </div>
            <div>
                <div class="hstat-n"><span data-count="<?= $perfect_days ?>"><?= $perfect_days ?></span></div>
                <div class="hstat-l">days you cleared the whole list</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</header>

<div class="wrap">

<section class="ch enter" style="padding-top:44px;">
    <div class="cal-nav">
        <a href="calendar.php?y=<?= $prev_y ?>&m=<?= $prev_m ?>" aria-label="Previous month">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <h2><?= $month_label ?></h2>
        <a href="calendar.php?y=<?= $next_y ?>&m=<?= $next_m ?>" aria-label="Next month">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>
        <?php if ($year != date('Y') || $month != date('n')): ?>
        <a href="calendar.php" style="width:auto; padding:0 16px; border-radius:999px; font-size:13px; font-weight:600;">This month</a>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="cal">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
            <div class="cal-dow"><?= $d ?></div>
            <?php endforeach; ?>

            <?php for ($i = 1; $i < $first_dow; $i++): ?>
            <div class="cal-cell blank"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $days_in_month; $d++):
                $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $count = $checkins_by_date[$date] ?? 0;
                $mood = $mood_by_date[$date] ?? null;
                $classes = 'cal-cell' . ($count > 0 ? ' filled' : '') . ($date === $today_str ? ' today' : '');
            ?>
            <div class="<?= $classes ?>" style="background:<?= cal_shade($count, $max_possible) ?>"
                 title="<?= date('j M', strtotime($date)) ?> — <?= $count ?> of <?= $max_possible ?> done">
                <span class="cal-n"><?= $d ?></span>
                <?php if ($count > 0): ?><span class="cal-dot"><?= $count ?></span><?php endif; ?>
                <?php if ($mood && isset(MOOD_META[$mood])): ?>
                <span class="cal-mood"><?= MOOD_META[$mood]['emoji'] ?></span>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>

        <div class="legend">
            <span><i style="background:var(--paper)"></i>nothing</span>
            <span><i style="background:#E3F2E4"></i>a start</span>
            <span><i style="background:#C4E7C6"></i>most of it</span>
            <span><i style="background:var(--shoot)"></i>the lot</span>
            <span style="margin-left:auto;">The small face is the mood you logged that day.</span>
        </div>
    </div>
</section>

</div>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
