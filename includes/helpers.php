
<?php
// The shared "chart card" wrapper (title row + info-dot + consistent body
// + empty-state) used everywhere a svg_*_chart() result gets displayed —
// see includes/chart_card.php. Required from here so every page that
// already does `require_once .../helpers.php` gets it for free.
require_once __DIR__ . '/chart_card.php';

// Returns array of the last 7 dates (Mon-Sun of current week), oldest first, 'Y-m-d'
function week_dates() {
    $dates = [];
    $today = new DateTime('today');
    $dow = (int)$today->format('N'); // 1 (Mon) - 7 (Sun)
    $monday = (clone $today)->modify('-' . ($dow - 1) . ' days');
    for ($i = 0; $i < 7; $i++) {
        $dates[] = (clone $monday)->modify("+$i days")->format('Y-m-d');
    }
    return $dates;
}

// Fetch log dates (as 'done') for a goal within the current week
function week_logs($pdo, $goal_id) {
    $dates = week_dates();
    $stmt = $pdo->prepare("SELECT log_date FROM goal_logs WHERE goal_id = ? AND status='done' AND log_date BETWEEN ? AND ?");
    $stmt->execute([$goal_id, $dates[0], end($dates)]);
    return array_column($stmt->fetchAll(), 'log_date');
}

// Current consecutive-day streak counting backward from today
function current_streak($pdo, $goal_id) {
    $stmt = $pdo->prepare("SELECT log_date FROM goal_logs WHERE goal_id = ? AND status='done' ORDER BY log_date DESC");
    $stmt->execute([$goal_id]);
    $logged = array_column($stmt->fetchAll(), 'log_date');
    $logged = array_flip($logged);

    $streak = 0;
    $cursor = new DateTime('today');
    // if today isn't logged yet, still allow streak to count from yesterday
    if (!isset($logged[$cursor->format('Y-m-d')])) {
        $cursor->modify('-1 day');
    }
    while (isset($logged[$cursor->format('Y-m-d')])) {
        $streak++;
        $cursor->modify('-1 day');
    }
    return $streak;
}

// Simple weekly completion percent (done days / 7) for the ring UI
function week_percent($pdo, $goal_id) {
    $done = count(week_logs($pdo, $goal_id));
    return round(($done / 7) * 100);
}

function get_category($pdo, $slug) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch();
}

function get_all_categories($pdo) {
    return $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll();
}

/* ============================================================
   Gamification: XP, levels, achievements
   XP is derived live from real activity — no XP is stored directly,
   so it can never drift out of sync with the actual check-in history.
   ============================================================ */

const XP_PER_CHECKIN = 10;
const XP_PER_LEVEL   = 150;

function total_checkins($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id WHERE g.user_id=? AND gl.status='done'");
    $stmt->execute([$uid]);
    return (int)$stmt->fetch()['c'];
}

function earned_achievements($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT a.* FROM user_achievements ua JOIN achievements a ON a.id=ua.achievement_id WHERE ua.user_id=? ORDER BY ua.earned_at DESC");
    $stmt->execute([$uid]);
    return $stmt->fetchAll();
}

function user_xp($pdo, $uid) {
    $checkins = total_checkins($pdo, $uid);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(a.xp_reward),0) bonus FROM user_achievements ua JOIN achievements a ON a.id=ua.achievement_id WHERE ua.user_id=?");
    $stmt->execute([$uid]);
    $bonus = (int)$stmt->fetch()['bonus'];
    return ($checkins * XP_PER_CHECKIN) + $bonus;
}

function user_level_info($pdo, $uid) {
    $xp = user_xp($pdo, $uid);
    $level = intdiv($xp, XP_PER_LEVEL) + 1;
    $into_level = $xp % XP_PER_LEVEL;
    return ['xp' => $xp, 'level' => $level, 'into_level' => $into_level, 'pct' => round(($into_level / XP_PER_LEVEL) * 100)];
}

// Checks all achievement criteria against real data and awards any newly-earned ones.
// Safe to call often — UNIQUE KEY on user_achievements prevents duplicates.
function evaluate_achievements($pdo, $uid) {
    $newly = [];
    $stmt = $pdo->prepare("SELECT code FROM achievements a JOIN user_achievements ua ON ua.achievement_id=a.id WHERE ua.user_id=?");
    $stmt->execute([$uid]);
    $have = array_column($stmt->fetchAll(), 'code');

    $checkins = total_checkins($pdo, $uid);
    $stmt = $pdo->prepare("SELECT id FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $goal_ids = array_column($stmt->fetchAll(), 'id');
    $best_streak = 0;
    foreach ($goal_ids as $gid) $best_streak = max($best_streak, current_streak($pdo, $gid));

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT category_id) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $distinct_cats = (int)$stmt->fetch()['c'];
    $total_cats = (int)$pdo->query("SELECT COUNT(*) c FROM categories")->fetch()['c'];

    $mood_streak = mood_streak_days($pdo, $uid);

    $perfect_week = false;
    if (count($goal_ids) > 0) {
        $possible = count($goal_ids) * 7;
        $done = 0;
        foreach ($goal_ids as $gid) $done += count(week_logs($pdo, $gid));
        $perfect_week = ($done >= $possible);
    }

    $criteria = [
        'first_checkin'  => $checkins >= 1,
        'streak_7'       => $best_streak >= 7,
        'streak_30'      => $best_streak >= 30,
        'checkins_25'    => $checkins >= 25,
        'checkins_100'   => $checkins >= 100,
        'five_goals'     => count($goal_ids) >= 5,
        'all_categories' => $distinct_cats >= $total_cats && $total_cats > 0,
        'perfect_week'   => $perfect_week,
        'mood_streak_7'  => $mood_streak >= 7,
    ];

    foreach ($criteria as $code => $met) {
        if ($met && !in_array($code, $have)) {
            $a = $pdo->prepare("SELECT id FROM achievements WHERE code=?");
            $a->execute([$code]);
            $row = $a->fetch();
            if ($row) {
                $ins = $pdo->prepare("INSERT IGNORE INTO user_achievements (user_id, achievement_id) VALUES (?,?)");
                $ins->execute([$uid, $row['id']]);
                if ($ins->rowCount() > 0) $newly[] = $code;
            }
        }
    }
    return $newly;
}

/* ============================================================
   Mood tracking
   ============================================================ */

const MOOD_META = [
    'happy'    => ['emoji' => '😄', 'label' => 'Happy',    'score' => 5, 'color' => '#D7F171'],
    'calm'     => ['emoji' => '😌', 'label' => 'Calm',     'score' => 4, 'color' => '#DDF5E5'],
    'sleepy'   => ['emoji' => '😴', 'label' => 'Sleepy',   'score' => 3, 'color' => '#C7D8FF'],
    'bored'    => ['emoji' => '😐', 'label' => 'Bored',    'score' => 3, 'color' => '#B9BFF5'],
    'stressed' => ['emoji' => '😣', 'label' => 'Stressed', 'score' => 2, 'color' => '#FFD7C2'],
    'sad'      => ['emoji' => '😢', 'label' => 'Sad',      'score' => 1, 'color' => '#EAA1D8'],
];

function todays_mood($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT * FROM mood_logs WHERE user_id=? AND log_date=?");
    $stmt->execute([$uid, date('Y-m-d')]);
    return $stmt->fetch();
}

function mood_streak_days($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT log_date FROM mood_logs WHERE user_id=? ORDER BY log_date DESC");
    $stmt->execute([$uid]);
    $logged = array_flip(array_column($stmt->fetchAll(), 'log_date'));
    $streak = 0;
    $cursor = new DateTime('today');
    if (!isset($logged[$cursor->format('Y-m-d')])) $cursor->modify('-1 day');
    while (isset($logged[$cursor->format('Y-m-d')])) { $streak++; $cursor->modify('-1 day'); }
    return $streak;
}

// Wellness score out of 100: average mood score (last 7 days) blended with sleep + stress
function wellness_score($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT mood, sleep_hours, stress_level FROM mood_logs WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();
    if (empty($rows)) return null;

    $mood_avg = 0; $sleep_avg = 0; $sleep_n = 0; $stress_penalty = 0;
    foreach ($rows as $r) {
        $mood_avg += MOOD_META[$r['mood']]['score'];
        if ($r['sleep_hours'] !== null) { $sleep_avg += (float)$r['sleep_hours']; $sleep_n++; }
        if ($r['stress_level'] === 'high') $stress_penalty += 2;
        elseif ($r['stress_level'] === 'medium') $stress_penalty += 1;
    }
    $mood_avg /= count($rows);
    $sleep_avg = $sleep_n > 0 ? $sleep_avg / $sleep_n : 7;

    $mood_component  = ($mood_avg / 5) * 60;               // up to 60 pts
    $sleep_component = min($sleep_avg / 8, 1) * 30;         // up to 30 pts
    $stress_component = max(0, 10 - $stress_penalty);       // up to 10 pts
    return (int)round($mood_component + $sleep_component + $stress_component);
}

/* ============================================================
   Forecast (ML) — reading the cache the Python script writes,
   and drawing the actual-vs-forecast line charts on forecast.php
   ============================================================ */

// Returns the decoded payload array for the given forecast type, or null
// if train_model.py hasn't been run yet for this user.
function get_forecast($pdo, $uid, $type) {
    $stmt = $pdo->prepare("SELECT payload, model_used, mae, rmse, generated_at FROM forecast_cache WHERE user_id=? AND forecast_type=?");
    $stmt->execute([$uid, $type]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $payload = json_decode($row['payload'], true);
    $payload['_generated_at'] = $row['generated_at'];
    return $payload;
}

// Draws a simple actual (solid) -> forecast (dashed) SVG line chart for one
// or more series. $series = ['Income' => ['actual'=>[...], 'forecast'=>[...],
// 'color'=>'#..', 'ci_lower'=>[...], 'ci_upper'=>[...]], ...] — ci_lower/
// ci_upper are optional, one value per forecast point; when present (and
// none of them null) a shaded 95% confidence band is drawn under the
// dashed forecast line, so the further-out projection visibly reads as
// less certain rather than as precise as the logged history.
// All series must share the same $labels (actual months/weeks + forecast months/weeks).
function svg_line_chart($labels, $series, $width = 640, $height = 200) {
    $pad_l = 46; $pad_b = 26; $pad_t = 14; $pad_r = 14;
    $plot_w = $width - $pad_l - $pad_r;
    $plot_h = $height - $pad_t - $pad_b;
    $n = count($labels);
    if ($n < 2) return '<p style="color:var(--ink-soft); font-size:13px;">Not enough data points to chart yet.</p>';

    $has_any_band = false;
    $all_vals = [0];
    foreach ($series as $s) {
        foreach ($s['actual'] as $v) $all_vals[] = $v;
        foreach ($s['forecast'] as $v) $all_vals[] = $v;
        if (!empty($s['ci_upper'])) {
            foreach ($s['ci_upper'] as $v) if ($v !== null) { $all_vals[] = $v; $has_any_band = true; }
        }
    }
    $max_v = max($all_vals) * 1.15 ?: 1;

    $x_for = fn($i) => $pad_l + ($i / ($n - 1)) * $plot_w;
    $y_for = fn($v) => $pad_t + $plot_h - ($v / $max_v) * $plot_h;

    $svg = "<svg width=\"100%\" height=\"$height\" viewBox=\"0 0 $width $height\" preserveAspectRatio=\"none\" style=\"overflow:visible;\">";
    // gridlines
    for ($g = 0; $g <= 3; $g++) {
        $gy = $pad_t + ($plot_h / 3) * $g;
        $svg .= "<line x1=\"$pad_l\" y1=\"$gy\" x2=\"" . ($width - $pad_r) . "\" y2=\"$gy\" stroke=\"#EFEFEF\" stroke-width=\"1\"/>";
    }
    foreach ($series as $name => $s) {
        $color = $s['color'];
        $actual_n = count($s['actual']);

        // confidence band, drawn first so the lines/dots sit on top of it
        if (!empty($s['ci_lower']) && !empty($s['ci_upper'])) {
            $band_ok = true;
            foreach ($s['ci_upper'] as $v) if ($v === null) { $band_ok = false; break; }
            foreach ($s['ci_lower'] as $v) if ($v === null) { $band_ok = false; break; }
            if ($band_ok) {
                $band = [];
                foreach ($s['ci_upper'] as $j => $v) $band[] = $x_for($actual_n + $j) . ',' . $y_for($v);
                for ($j = count($s['ci_lower']) - 1; $j >= 0; $j--) $band[] = $x_for($actual_n + $j) . ',' . $y_for($s['ci_lower'][$j]);
                $svg .= '<polygon points="' . implode(' ', $band) . '" fill="' . $color . '" opacity="0.15" stroke="none"/>';
            }
        }
        // actual (solid)
        $pts = [];
        for ($i = 0; $i < $actual_n; $i++) $pts[] = $x_for($i) . ',' . $y_for($s['actual'][$i]);
        if (count($pts) >= 2) {
            $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>';
        }
        // forecast (dashed) — continues from the last actual point
        if (!empty($s['forecast'])) {
            $fpts = [];
            if ($actual_n > 0) $fpts[] = $x_for($actual_n - 1) . ',' . $y_for($s['actual'][$actual_n - 1]);
            foreach ($s['forecast'] as $j => $v) $fpts[] = $x_for($actual_n + $j) . ',' . $y_for($v);
            $svg .= '<polyline points="' . implode(' ', $fpts) . '" fill="none" stroke="' . $color . '" stroke-width="3" stroke-dasharray="6,6" stroke-linecap="round" stroke-linejoin="round"/>';
        }
        // dots
        for ($i = 0; $i < $actual_n; $i++) $svg .= '<circle cx="' . $x_for($i) . '" cy="' . $y_for($s['actual'][$i]) . '" r="4" fill="' . $color . '"/>';
        foreach ($s['forecast'] as $j => $v) $svg .= '<circle cx="' . $x_for($actual_n + $j) . '" cy="' . $y_for($v) . '" r="4" fill="#fff" stroke="' . $color . '" stroke-width="2"/>';
    }
    // x-axis labels (skip some if too many)
    $step = max(1, (int)ceil($n / 8));
    for ($i = 0; $i < $n; $i += $step) {
        $svg .= '<text x="' . $x_for($i) . '" y="' . ($height - 6) . '" font-size="10" fill="#74747A" text-anchor="middle">' . htmlspecialchars($labels[$i]) . '</text>';
    }
    $svg .= '</svg>';

    $legend = '<div style="display:flex; gap:16px; margin-top:8px; flex-wrap:wrap;">';
    foreach ($series as $name => $s) {
        $legend .= '<span style="font-size:12px; font-weight:700; color:var(--ink-soft);"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $s['color'] . ';margin-right:6px;"></span>' . htmlspecialchars($name) . '</span>';
    }
    $legend .= '<span style="font-size:11.5px; color:var(--ink-soft); margin-left:auto;">— solid = actual &nbsp; ┄ dashed = forecast' . ($has_any_band ? ' &nbsp; ▨ shaded = 95% confidence' : '') . '</span></div>';

    return $svg . $legend;
}

/* ============================================================
   Productivity & Habit analysis engine (Milestone 2)
   Rule-based, live-computed analytics — the same explainable-logic
   philosophy as coach.php. The *trend forecast* built on top of this
   (next week's completion % / productivity score) lives in
   ml/train_model.py and is read back via get_forecast() above.
   ============================================================ */

// Minutes invested per category over the last $days days: each goal's
// own est_minutes (set on the Add Goal form) × check-ins logged, PLUS any
// real Focus Session minutes logged against a goal in that category.
// Focus Sessions with no linked goal are returned separately as
// "unassigned_minutes" (shown as a "Deep Work" slice by the caller).
// This is a mix of self-reported estimate and real timer data — the
// Time Allocation chart says so via its info tooltip.
function category_time_allocation($pdo, $uid, $days = 7) {
    $stmt = $pdo->prepare(
        "SELECT c.name, c.color, SUM(combined.minutes) AS minutes FROM (
            SELECT g.category_id, g.est_minutes AS minutes FROM goal_logs gl
                JOIN goals g ON g.id = gl.goal_id
                WHERE g.user_id = ? AND gl.status = 'done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            UNION ALL
            SELECT g.category_id, fs.actual_minutes AS minutes FROM focus_sessions fs
                JOIN goals g ON g.id = fs.goal_id
                WHERE fs.user_id = ? AND fs.goal_id IS NOT NULL AND fs.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         ) combined
         JOIN categories c ON c.id = combined.category_id
         GROUP BY c.id, c.name, c.color
         HAVING minutes > 0
         ORDER BY minutes DESC"
    );
    $stmt->execute([$uid, $days - 1, $uid, $days]);
    $rows = $stmt->fetchAll();

    $stmt2 = $pdo->prepare(
        "SELECT COALESCE(SUM(actual_minutes),0) m FROM focus_sessions
         WHERE user_id = ? AND goal_id IS NULL AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt2->execute([$uid, $days]);
    $unassigned = (int)$stmt2->fetch()['m'];

    return ['categories' => $rows, 'unassigned_minutes' => $unassigned];
}

// The single active goal with the longest current streak — used for the
// "Best streak" KPI card on the Productivity & Habits tab.
function overall_best_streak($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT id, title FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $best = 0; $best_title = null;
    foreach ($stmt->fetchAll() as $g) {
        $s = current_streak($pdo, $g['id']);
        if ($s > $best) { $best = $s; $best_title = $g['title']; }
    }
    return ['days' => $best, 'title' => $best_title];
}

// Per-goal snapshot (this week's % + current streak) for the Habit
// Overview list on the Forecast page.
function active_goals_with_progress($pdo, $uid, $limit = 8) {
    $stmt = $pdo->prepare(
        "SELECT g.id, g.title, g.est_minutes, c.name AS category_name
         FROM goals g JOIN categories c ON c.id = g.category_id
         WHERE g.user_id = ? AND g.is_active = 1 ORDER BY g.created_at DESC LIMIT ?"
    );
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll() as $g) {
        $out[] = [
            'id' => (int)$g['id'],
            'title' => $g['title'],
            'category' => $g['category_name'],
            'pct' => week_percent($pdo, $g['id']),
            'streak' => current_streak($pdo, $g['id']),
            'est_minutes' => (int)$g['est_minutes'],
        ];
    }
    return $out;
}

// This week's strongest- and weakest-performing category by completion
// rate (same logic analytics.php uses), reused here for Forecast insights.
function best_worst_category_week($pdo, $uid) {
    $stmt = $pdo->prepare(
        "SELECT c.name, c.id, COUNT(g.id) goal_count,
            (SELECT COUNT(*) FROM goal_logs gl JOIN goals g2 ON g2.id=gl.goal_id
             WHERE g2.category_id=c.id AND g2.user_id=? AND gl.status='done'
             AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) as done_week
         FROM categories c LEFT JOIN goals g ON g.category_id=c.id AND g.user_id=? AND g.is_active=1
         GROUP BY c.id HAVING goal_count > 0"
    );
    $stmt->execute([$uid, $uid]);
    $best = null; $worst = null;
    foreach ($stmt->fetchAll() as $r) {
        $rate = ($r['goal_count'] * 7) > 0 ? $r['done_week'] / ($r['goal_count'] * 7) : 0;
        $r['rate'] = $rate;
        if ($best === null || $rate > $best['rate']) $best = $r;
        if ($worst === null || $rate < $worst['rate']) $worst = $r;
    }
    return [$best, $worst];
}

/* ============================================================
   Focus Sessions (real timer + log — feeds Productivity Analysis)
   ============================================================ */

// Aggregate stats over the last $days days: session count, total minutes,
// average minutes per session, and success rate (% completed vs interrupted).
function focus_session_stats($pdo, $uid, $days = 7) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) n, COALESCE(SUM(actual_minutes),0) total_minutes,
                COALESCE(AVG(actual_minutes),0) avg_minutes,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed_n
         FROM focus_sessions WHERE user_id=? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt->execute([$uid, $days]);
    $r = $stmt->fetch();
    $n = (int)$r['n'];
    return [
        'sessions' => $n,
        'total_minutes' => (int)$r['total_minutes'],
        'avg_minutes' => $n > 0 ? round((float)$r['avg_minutes'], 1) : 0,
        'success_rate' => $n > 0 ? round(($r['completed_n'] / $n) * 100) : 0,
    ];
}

// Most recent sessions, newest first, with the linked goal's title (if any).
function recent_focus_sessions($pdo, $uid, $limit = 6) {
    $stmt = $pdo->prepare(
        "SELECT fs.*, g.title AS goal_title FROM focus_sessions fs
         LEFT JOIN goals g ON g.id = fs.goal_id
         WHERE fs.user_id=? ORDER BY fs.started_at DESC LIMIT ?"
    );
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/* ============================================================
   Habit Score, Tasks Completed, Weekly Goal Progress
   (live KPIs for Productivity Analysis — see ml/train_model.py for
   the *forecasted* Productivity Score, which is a different number)
   ============================================================ */

// A 4-week rolling average completion rate — "how consistent have you
// been lately", distinct from the forecasted Productivity Score (which
// also factors in estimated/real time invested).
function habit_score($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $n_goals = (int)$stmt->fetch()['c'];
    if ($n_goals === 0) return null;

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
         WHERE g.user_id=? AND gl.status='done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 27 DAY)"
    );
    $stmt->execute([$uid]);
    $done = (int)$stmt->fetch()['c'];
    $possible = $n_goals * 28;
    return $possible > 0 ? min(100, round(($done / $possible) * 100)) : 0;
}

// [done, total] check-ins for today, across all active goals.
function tasks_completed_today($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $total = (int)$stmt->fetch()['c'];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
         WHERE g.user_id=? AND gl.status='done' AND gl.log_date=CURDATE()"
    );
    $stmt->execute([$uid]);
    $done = (int)$stmt->fetch()['c'];
    return ['done' => $done, 'total' => $total];
}

// This week's aggregate completion (%) vs a target — the "Weekly Goal
// Progress" bar. Target defaults to 90%, not 100%, since a single missed
// check-in across several goals shouldn't read as "failed the week".
function weekly_goal_progress($pdo, $uid, $target_pct = 90) {
    $stmt = $pdo->prepare("SELECT id FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $goal_ids = array_column($stmt->fetchAll(), 'id');
    if (empty($goal_ids)) return ['pct' => 0, 'target' => $target_pct];
    $possible = count($goal_ids) * 7;
    $done = 0;
    foreach ($goal_ids as $gid) $done += count(week_logs($pdo, $gid));
    $pct = $possible > 0 ? round(($done / $possible) * 100) : 0;
    return ['pct' => $pct, 'target' => $target_pct];
}

/* ============================================================
   Reminders — in-app only, no email/push sending is wired up.
   Shown on the Reminders page and as a "Today" list on the Dashboard.
   ============================================================ */

function reminders_for_today($pdo, $uid) {
    $today_abbr = date('D'); // "Mon", "Tue", ...
    $stmt = $pdo->prepare(
        "SELECT r.*, g.title AS goal_title FROM reminders r LEFT JOIN goals g ON g.id = r.goal_id
         WHERE r.user_id=? AND r.is_active=1 ORDER BY r.remind_time"
    );
    $stmt->execute([$uid]);
    $today_list = [];
    foreach ($stmt->fetchAll() as $r) {
        $days = array_map('trim', explode(',', $r['days_of_week']));
        if (in_array($today_abbr, $days, true)) $today_list[] = $r;
    }
    return $today_list;
}

function all_reminders($pdo, $uid) {
    $stmt = $pdo->prepare(
        "SELECT r.*, g.title AS goal_title FROM reminders r LEFT JOIN goals g ON g.id = r.goal_id
         WHERE r.user_id=? ORDER BY r.remind_time"
    );
    $stmt->execute([$uid]);
    return $stmt->fetchAll();
}

/* ============================================================
   Calendar View — per-day check-in intensity + mood, for one month
   ============================================================ */

function month_calendar_data($pdo, $uid, $year, $month) {
    $start = sprintf('%04d-%02d-01', $year, $month);
    $days_in_month = (int)date('t', strtotime($start));
    $end = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

    $stmt = $pdo->prepare(
        "SELECT gl.log_date, COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
         WHERE g.user_id=? AND gl.status='done' AND gl.log_date BETWEEN ? AND ?
         GROUP BY gl.log_date"
    );
    $stmt->execute([$uid, $start, $end]);
    $checkins_by_date = [];
    foreach ($stmt->fetchAll() as $r) $checkins_by_date[$r['log_date']] = (int)$r['c'];

    $stmt = $pdo->prepare("SELECT log_date, mood FROM mood_logs WHERE user_id=? AND log_date BETWEEN ? AND ?");
    $stmt->execute([$uid, $start, $end]);
    $mood_by_date = [];
    foreach ($stmt->fetchAll() as $r) $mood_by_date[$r['log_date']] = $r['mood'];

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $active_goals = (int)$stmt->fetch()['c'];

    return [
        'days_in_month' => $days_in_month,
        'checkins_by_date' => $checkins_by_date,
        'mood_by_date' => $mood_by_date,
        'active_goals' => $active_goals,
    ];
}

// The weekday (all-time) with the most logged check-ins — same query
// analytics.php uses, reused here for the Productivity & Habits insights.
function best_weekday($pdo, $uid) {
    $stmt = $pdo->prepare(
        "SELECT DAYNAME(gl.log_date) dname, COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
         WHERE g.user_id=? AND gl.status='done' GROUP BY DAYOFWEEK(gl.log_date), dname ORDER BY c DESC LIMIT 1"
    );
    $stmt->execute([$uid]);
    return $stmt->fetch();
}

/* ============================================================
   Phase 3 additions — extra graphs for Dashboard, Insights &
   Reports, and Finance: live PHP/SQL, no retraining needed.
   ============================================================ */

// This week's check-ins per day (Mon-Sun), for a simple bar chart —
// same shape analytics.php has used since Phase 2, centralised here so
// the Dashboard can show the same chart without duplicating the query.
function weekly_checkin_bars($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $active_goal_count = (int)$stmt->fetch()['c'];

    $dates = week_dates();
    $stmt = $pdo->prepare("SELECT gl.log_date, COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
        WHERE g.user_id=? AND gl.status='done' AND gl.log_date BETWEEN ? AND ? GROUP BY gl.log_date");
    $stmt->execute([$uid, $dates[0], end($dates)]);
    $by_date = [];
    foreach ($stmt->fetchAll() as $r) $by_date[$r['log_date']] = (int)$r['c'];

    $bars = [];
    foreach ($dates as $d) {
        $dt = new DateTime($d);
        $bars[] = ['label' => $dt->format('D'), 'count' => $by_date[$d] ?? 0, 'max' => max(1, $active_goal_count)];
    }
    return $bars;
}

// Weekly-average wellness score (mood + sleep + stress blend, same
// formula as wellness_score()) over the last N weeks — for a mood/
// wellness trend line chart. Weeks with no mood logs are skipped so
// the line only draws where there's real data.
function wellness_score_weekly($pdo, $uid, $weeks = 8) {
    $stmt = $pdo->prepare("SELECT log_date, mood, sleep_hours, stress_level FROM mood_logs
        WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY log_date");
    $stmt->execute([$uid, $weeks * 7 - 1]);
    $rows = $stmt->fetchAll();
    if (empty($rows)) return ['labels' => [], 'scores' => []];

    $buckets = [];
    foreach ($rows as $r) {
        $wk = (new DateTime($r['log_date']))->format('o-\WW'); // ISO year-week
        $buckets[$wk][] = $r;
    }
    ksort($buckets);

    $labels = []; $scores = []; $i = 1;
    foreach ($buckets as $wk => $wrows) {
        $mood_avg = 0; $sleep_avg = 0; $sleep_n = 0; $stress_penalty = 0;
        foreach ($wrows as $r) {
            $mood_avg += MOOD_META[$r['mood']]['score'];
            if ($r['sleep_hours'] !== null) { $sleep_avg += (float)$r['sleep_hours']; $sleep_n++; }
            if ($r['stress_level'] === 'high') $stress_penalty += 2;
            elseif ($r['stress_level'] === 'medium') $stress_penalty += 1;
        }
        $mood_avg /= count($wrows);
        $sleep_avg = $sleep_n > 0 ? $sleep_avg / $sleep_n : 7;
        $score = ($mood_avg / 5) * 60 + min($sleep_avg / 8, 1) * 30 + max(0, 10 - $stress_penalty);
        $labels[] = 'Wk ' . $i;
        $scores[] = round($score);
        $i++;
    }
    return ['labels' => $labels, 'scores' => $scores];
}

// Live month-by-month income/expense totals straight from the
// transactions table — no Python/ML dependency, always fresh. Used for
// the Finance page's history chart, the Dashboard finance snapshot, and
// the Insights & Reports financial summary.
function monthly_transaction_totals($pdo, $uid, $months = 6) {
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(txn_date, '%Y-%m') ym, type, COALESCE(SUM(amount),0) total
         FROM transactions WHERE user_id=? AND txn_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
         GROUP BY ym, type ORDER BY ym"
    );
    $stmt->execute([$uid, $months]);
    $by_month = [];
    foreach ($stmt->fetchAll() as $r) {
        if (!isset($by_month[$r['ym']])) $by_month[$r['ym']] = ['income' => 0.0, 'expense' => 0.0];
        $by_month[$r['ym']][$r['type']] = (float)$r['total'];
    }
    ksort($by_month);
    $labels = array_keys($by_month);
    $income = array_map(fn($m) => $m['income'], $by_month);
    $expense = array_map(fn($m) => $m['expense'], $by_month);
    return ['months' => $labels, 'income' => array_values($income), 'expense' => array_values($expense)];
}

// Truncates a label for tight chart space — plain substr (no mbstring
// dependency, since not every XAMPP install has it enabled) which is
// fine here since category/habit names in this app are short and plain.
function short_label($s, $len = 12) {
    return strlen($s) > $len ? substr($s, 0, $len - 1) . '…' : $s;
}

// This week's completion % for every category that has at least one
// active goal — the full comparison behind analytics.php's best/worst
// callouts, now also drawable as its own bar chart.
function category_completion_this_week($pdo, $uid) {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name, c.color, COUNT(g.id) goal_count,
            (SELECT COUNT(*) FROM goal_logs gl JOIN goals g2 ON g2.id=gl.goal_id
             WHERE g2.category_id=c.id AND g2.user_id=? AND gl.status='done'
             AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) as done_week
         FROM categories c JOIN goals g ON g.category_id=c.id AND g.user_id=? AND g.is_active=1
         GROUP BY c.id, c.name, c.color HAVING goal_count > 0"
    );
    $stmt->execute([$uid, $uid]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $possible = $r['goal_count'] * 7;
        $r['rate_pct'] = $possible > 0 ? round(($r['done_week'] / $possible) * 100) : 0;
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['rate_pct'] <=> $a['rate_pct']);
    return $rows;
}

// Draws a donut/ring chart. $segments = ['Label' => ['value'=>float,'color'=>'#hex'], ...]
// Returns ['svg'=>..., 'legend'=>['Label'=>['pct'=>.., 'value'=>.., 'color'=>..]], 'total'=>..]
// so the caller can render an SVG ring plus a matching text legend beside it.
function svg_donut_chart($segments, $size = 168, $stroke_width = 24) {
    $total = array_sum(array_column($segments, 'value'));
    if ($total <= 0) {
        return ['svg' => '', 'legend' => [], 'total' => 0];
    }
    $r = ($size - $stroke_width) / 2;
    $circumference = 2 * M_PI * $r;
    $c = $size / 2;

    $svg = "<svg class=\"donut-ring\" width=\"$size\" height=\"$size\" viewBox=\"0 0 $size $size\" style=\"transform:rotate(-90deg);\">";
    $svg .= "<circle cx=\"$c\" cy=\"$c\" r=\"$r\" fill=\"none\" stroke=\"var(--lightgray)\" stroke-width=\"$stroke_width\"/>";

    $offset_acc = 0.0;
    $legend = [];
    foreach ($segments as $name => $seg) {
        $pct = $seg['value'] / $total;
        $dash = max(0, $pct * $circumference - 1.5); // 1.5px gap so slices are visually distinct
        $gap = $circumference - $dash;
        $dashoffset = -$offset_acc * $circumference;
        $svg .= "<circle cx=\"$c\" cy=\"$c\" r=\"$r\" fill=\"none\" stroke=\"{$seg['color']}\" stroke-width=\"$stroke_width\" "
              . "stroke-dasharray=\"$dash $gap\" stroke-dashoffset=\"$dashoffset\" stroke-linecap=\"round\"/>";
        $offset_acc += $pct;
        $legend[$name] = ['pct' => round($pct * 100), 'value' => $seg['value'], 'color' => $seg['color']];
    }
    $svg .= '</svg>';
    return ['svg' => $svg, 'legend' => $legend, 'total' => $total];
}

/* ============================================================
   Phase 3, round 2 — "vs last week" trend deltas and per-weekday
   productivity scoring, to match the reference dashboard mockup's
   KPI trend indicators and Top/Least Productive Day cards.
   ============================================================ */

// Same rolling-consistency formula as habit_score(), but the 28-day
// window is shifted back 7 days — i.e. "what was your habit score as
// of last week" — so the two numbers are directly comparable.
function habit_score_prev_week($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $n_goals = (int)$stmt->fetch()['c'];
    if ($n_goals === 0) return null;

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
         WHERE g.user_id=? AND gl.status='done'
         AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 34 DAY)
         AND gl.log_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
    );
    $stmt->execute([$uid]);
    $done = (int)$stmt->fetch()['c'];
    $possible = $n_goals * 28;
    return $possible > 0 ? min(100, round(($done / $possible) * 100)) : 0;
}

// Total focus-session minutes logged 8-14 days ago — the week before
// focus_session_stats($pdo, $uid, 7)'s current window — for the Focus
// Time KPI's "vs last week" delta.
function focus_minutes_prev_week($pdo, $uid) {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(actual_minutes),0) m FROM focus_sessions
         WHERE user_id=? AND started_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
         AND started_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $stmt->execute([$uid]);
    return (int)$stmt->fetch()['m'];
}

// How many check-ins were completed on the given calendar date — used
// to compare "tasks completed today" against the same weekday last
// week (a fairer comparison than yesterday, since weekly routines
// often differ by day-of-week).
function tasks_completed_on($pdo, $uid, $date) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
         WHERE g.user_id=? AND gl.status='done' AND gl.log_date=?"
    );
    $stmt->execute([$uid, $date]);
    return (int)$stmt->fetch()['c'];
}

// Builds a small "+12% vs last week" / "−4% vs last week" style badge
// from a current and previous value. Returns null when there's nothing
// meaningful to compare against (no previous data), so callers can
// simply skip rendering the badge.
function trend_delta($current, $previous, $unit = '%') {
    if ($previous === null || $current === null) return null;
    if ($previous == 0) {
        if ($current == 0) return null;
        return ['dir' => 'up', 'text' => 'new vs last week'];
    }
    $pct = round((($current - $previous) / $previous) * 100);
    if ($pct == 0) return ['dir' => 'flat', 'text' => 'same as last week'];
    $dir = $pct > 0 ? 'up' : 'down';
    return ['dir' => $dir, 'text' => ($pct > 0 ? '+' : '') . $pct . '% vs last week'];
}

// Builds a small "+2 vs last Tue" style badge from a whole-number
// difference (used for Tasks Completed Today, where a percentage would
// be misleading on small counts like 1 vs 2).
function trend_delta_count($current, $previous, $day_label) {
    $diff = $current - $previous;
    if ($diff == 0) return ['dir' => 'flat', 'text' => "same as last $day_label"];
    $dir = $diff > 0 ? 'up' : 'down';
    return ['dir' => $dir, 'text' => ($diff > 0 ? '+' : '') . $diff . " vs last $day_label"];
}

// The longest streak any single goal has ever reached, all-time — used
// as a "personal best" reference point next to the live Current Streak
// KPI (a true historical streak-on-this-date isn't reconstructible
// cheaply, so this gives an honest, still-useful comparison instead).
function all_time_best_streak($pdo, $uid) {
    $stmt = $pdo->prepare(
        "SELECT gl.log_date FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
         WHERE g.user_id=? AND gl.status='done' GROUP BY gl.log_date ORDER BY gl.log_date"
    );
    $stmt->execute([$uid]);
    $dates = array_column($stmt->fetchAll(), 'log_date');
    if (empty($dates)) return 0;
    $best = 1; $run = 1;
    for ($i = 1; $i < count($dates); $i++) {
        $prev = new DateTime($dates[$i - 1]);
        $cur = new DateTime($dates[$i]);
        $diff = (int)$prev->diff($cur)->days;
        if ($diff === 1) { $run++; } elseif ($diff > 1) { $run = 1; }
        if ($run > $best) $best = $run;
    }
    return $best;
}

// Average per-weekday completion score (0-100), across active goals,
// over the last $weeks weeks — the "Top Productive Day" / "Least
// Productive Day" mini cards need a comparable numeric score per day,
// not just a raw all-time check-in count like best_weekday() gives.
// Returns rows sorted best-first: [['dname'=>'Wednesday','score'=>88,'n'=>...], ...]
// Days with zero possible check-ins (no active goals existed / no data
// yet) are omitted so an untouched day doesn't wrongly show as "worst".
function weekday_productivity_scores($pdo, $uid, $weeks = 8) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $n_goals = (int)$stmt->fetch()['c'];
    if ($n_goals === 0) return [];

    $stmt = $pdo->prepare(
        "SELECT DAYOFWEEK(gl.log_date) dow, DAYNAME(gl.log_date) dname, COUNT(*) c
         FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
         WHERE g.user_id=? AND gl.status='done'
         AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY dow, dname"
    );
    $stmt->execute([$uid, $weeks * 7 - 1]);
    $by_dow = [];
    foreach ($stmt->fetchAll() as $r) $by_dow[(int)$r['dow']] = ['dname' => $r['dname'], 'c' => (int)$r['c']];

    // How many of each weekday have actually occurred in the window
    // (so a partial final week doesn't over- or under-count possible
    // check-ins for that weekday).
    $occurrences = array_fill(1, 7, 0);
    $cursor = new DateTime('-' . ($weeks * 7 - 1) . ' days');
    for ($i = 0; $i < $weeks * 7; $i++) {
        $occurrences[(int)$cursor->format('N') % 7 + 1]++; // DAYOFWEEK: 1=Sun..7=Sat
        $cursor->modify('+1 day');
    }

    $rows = [];
    foreach ($by_dow as $dow => $info) {
        $possible = $occurrences[$dow] * $n_goals;
        if ($possible <= 0) continue;
        $rows[] = [
            'dname' => $info['dname'],
            'score' => min(100, round(($info['c'] / $possible) * 100)),
            'n' => $info['c'],
        ];
    }
    usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
    return $rows;
}

/* ============================================================
   Financial Goals — savings/emergency-fund/debt-payoff/etc targets,
   each tracked with its own contribution history. Everything here is
   simple, explainable math (no black-box model) matching the rest of
   this file's philosophy: a linear projection of recent contribution
   pace, backed by an honest best/worst-case band from that pace's own
   variability, plus a plain risk label anyone can sanity-check.
   current_amount is never stored — always starting_amount + SUM of
   goal_contributions, so it can never drift out of sync (same "derive,
   don't store" approach as user_xp() above).
   ============================================================ */

const GOAL_TYPE_META = [
    'savings'        => ['label' => 'Savings',        'icon' => '💰'],
    'emergency_fund' => ['label' => 'Emergency Fund',  'icon' => '🛟'],
    'debt_payoff'    => ['label' => 'Debt Payoff',     'icon' => '📉'],
    'investment'     => ['label' => 'Investment',      'icon' => '📈'],
    'purchase'       => ['label' => 'Purchase',        'icon' => '🛍️'],
    'education'      => ['label' => 'Education Fund',  'icon' => '🎓'],
    'travel'         => ['label' => 'Travel Fund',     'icon' => '✈️'],
    'income_target'  => ['label' => 'Income Target',   'icon' => '🎯'],
];

// All of a user's active financial goals, each with its current amount
// (derived) and a full forecast attached — everything a Goals tab needs
// in one call.
function financial_goals_with_forecast($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT * FROM financial_goals WHERE user_id=? AND is_active=1 ORDER BY target_date ASC");
    $stmt->execute([$uid]);
    $goals = $stmt->fetchAll();
    $out = [];
    $last_stmt = $pdo->prepare("SELECT MAX(contributed_at) d FROM goal_contributions WHERE goal_id=?");
    foreach ($goals as $g) {
        $monthly = goal_monthly_contributions($pdo, $g['id']);
        $current = (float)$g['starting_amount'] + array_sum($monthly['amounts']);
        $g['current_amount'] = round($current, 2);
        $last_stmt->execute([$g['id']]);
        $g['last_contribution_at'] = $last_stmt->fetch()['d'];
        $g['forecast'] = build_goal_forecast($g, $monthly);
        $out[] = $g;
    }
    return $out;
}

// Month-by-month contribution totals for one goal, oldest first —
// same shape as monthly_transaction_totals(), used both to derive the
// running current_amount and as the input series for the forecast.
function goal_monthly_contributions($pdo, $goal_id) {
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(contributed_at, '%Y-%m') ym, COALESCE(SUM(amount),0) total
         FROM goal_contributions WHERE goal_id=? GROUP BY ym ORDER BY ym"
    );
    $stmt->execute([$goal_id]);
    $months = []; $amounts = [];
    foreach ($stmt->fetchAll() as $r) { $months[] = $r['ym']; $amounts[] = (float)$r['total']; }
    return ['months' => $months, 'amounts' => $amounts];
}

// The Goal Achievement Forecast: predicted completion date, a Low/
// Medium/High risk label, a best/expected/worst-case date band, and the
// monthly contribution needed to still hit the deadline.
//
// Method: average monthly contribution pace over the last up to 6
// months (or the lifetime average if there's less history than that)
// projects forward at a constant rate until current_amount reaches
// target_amount. The best/worst case widens that same rate by one
// standard deviation of the monthly amounts actually seen — a goal
// contributed to consistently gets a tight band, one contributed to
// erratically gets an honestly wide one. This mirrors forecast_series()
// in ml/forecasting.py (pace-based projection + a variability-derived
// band) without needing enough monthly data points to backtest ARIMA.
function build_goal_forecast($goal, $monthly) {
    $target = (float)$goal['target_amount'];
    $current = (float)$goal['starting_amount'] + array_sum($monthly['amounts']);
    $remaining = max(0, $target - $current);
    $today = new DateTime('today');
    $target_date = new DateTime($goal['target_date']);
    $days_remaining = $today <= $target_date ? (int)$today->diff($target_date)->days : -1 * (int)$today->diff($target_date)->days;
    $months_remaining = $days_remaining / 30.44;

    if ($remaining <= 0) {
        return ['status' => 'achieved', 'message' => 'Goal reached! 🎉'];
    }

    $recent = array_slice($monthly['amounts'], -6);
    if (empty($recent)) {
        return [
            'status' => 'no_data',
            'message' => 'Add your first contribution to unlock a completion forecast.',
            'required_monthly' => $months_remaining > 0 ? round($remaining / $months_remaining, 2) : $remaining,
        ];
    }

    $n = count($recent);
    $avg = array_sum($recent) / $n;
    $variance = $n > 1 ? array_sum(array_map(fn($v) => ($v - $avg) ** 2, $recent)) / ($n - 1) : 0;
    $stdev = sqrt($variance);

    $project = function ($monthly_rate) use ($remaining, $today) {
        if ($monthly_rate <= 0) return null; // never gets there at this rate
        $months_needed = $remaining / $monthly_rate;
        return (clone $today)->modify('+' . (int)round($months_needed * 30.44) . ' days');
    };

    $expected_date = $project($avg);
    $best_date = $project($avg + $stdev);       // faster pace = sooner
    $worst_date = $project(max(0.01, $avg - $stdev)); // slower pace = later

    // Risk: compare the expected and worst-case dates against the deadline.
    if ($expected_date && $expected_date <= $target_date && $worst_date && $worst_date <= (clone $target_date)->modify('+30 days')) {
        $risk = 'low';
    } elseif ($best_date && $best_date <= $target_date) {
        $risk = 'medium';
    } else {
        $risk = 'high';
    }

    // Velocity trend: last half of the window vs the first half.
    $half = intdiv($n, 2);
    $velocity = 'steady';
    if ($half >= 1 && $n >= 2) {
        $first_avg = array_sum(array_slice($recent, 0, $half)) / $half;
        $second_avg = array_sum(array_slice($recent, $half)) / ($n - $half);
        if ($first_avg > 0) {
            $change = ($second_avg - $first_avg) / $first_avg;
            if ($change > 0.15) $velocity = 'accelerating';
            elseif ($change < -0.15) $velocity = 'slowing';
        }
    }

    $required_monthly = $months_remaining > 0 ? round($remaining / $months_remaining, 2) : $remaining;

    // Abandonment signal: goal risk detection (J) — a goal with no deposit
    // in a long stretch reads very differently from one that's merely
    // slow-paced, so it gets its own flag rather than hiding inside "high risk".
    $days_since_last = null;
    if (!empty($goal['last_contribution_at'])) {
        $days_since_last = (int)(new DateTime($goal['last_contribution_at']))->diff($today)->days;
    }
    $abandoned = $days_since_last !== null && $days_since_last >= 45;

    $insights = [];
    if ($expected_date) {
        $insights[] = 'At your current pace (₹' . number_format($avg, 0) . '/month), you\'ll reach this goal around '
            . $expected_date->format('d M Y') . '.';
    }
    if ($risk !== 'low' && $required_monthly > $avg) {
        $insights[] = 'To hit your ' . $target_date->format('d M Y') . ' deadline, increase contributions to about ₹'
            . number_format($required_monthly, 0) . '/month (₹' . number_format(max(0, $required_monthly - $avg), 0) . ' more than your current pace).';
    }
    if ($velocity === 'slowing') {
        $insights[] = 'Your contribution pace has slowed recently — a couple of catch-up deposits now would flatten that.';
    } elseif ($velocity === 'accelerating') {
        $insights[] = 'Your contribution pace is accelerating — keep it up and you may finish ahead of schedule.';
    }
    if ($abandoned) {
        $insights[] = "It's been $days_since_last days since your last contribution — this goal may be stalling.";
    }

    // Burn-down / projection band chart series: actual cumulative balance
    // so far (starting_amount + running contributions, month by month),
    // then a projected line at the average pace plus a shaded band at the
    // ± one-standard-deviation pace, out to whichever is later — the
    // deadline or the expected finish — capped at 12 months so a very
    // slow-paced goal doesn't stretch the chart absurdly far.
    $cum = (float)$goal['starting_amount'];
    $history_values = [];
    foreach ($monthly['amounts'] as $amt) { $cum += $amt; $history_values[] = round($cum, 2); }
    $history_labels = $monthly['months'];

    $months_to_chart = (int)ceil(max($months_remaining, $expected_date ? ($today->diff($expected_date)->days / 30.44) : 0));
    $months_to_chart = max(3, min(12, $months_to_chart));
    $forecast_labels = $forecast_values = $forecast_lower = $forecast_upper = [];
    $base = end($history_values) ?: (float)$goal['starting_amount'];
    for ($i = 1; $i <= $months_to_chart; $i++) {
        $forecast_labels[] = '+' . $i . 'mo';
        $forecast_values[] = round($base + $avg * $i, 2);
        $forecast_lower[] = round($base + max(0, $avg - $stdev) * $i, 2);
        $forecast_upper[] = round($base + ($avg + $stdev) * $i, 2);
    }

    return [
        'status' => 'ok',
        'risk' => $risk,
        'velocity' => $velocity,
        'avg_monthly' => round($avg, 2),
        'required_monthly' => $required_monthly,
        'remaining_amount' => round($remaining, 2),
        'expected_date' => $expected_date ? $expected_date->format('Y-m-d') : null,
        'best_date' => $best_date ? $best_date->format('Y-m-d') : null,
        'worst_date' => $worst_date ? $worst_date->format('Y-m-d') : null,
        'days_since_last_contribution' => $days_since_last,
        'abandoned' => $abandoned,
        'insights' => $insights,
        'chart' => [
            'history_labels' => $history_labels,
            'history_values' => $history_values,
            'forecast_labels' => $forecast_labels,
            'forecast_values' => $forecast_values,
            'forecast_lower' => $forecast_lower,
            'forecast_upper' => $forecast_upper,
        ],
    ];
}

// Small colored pill for a goal's risk label — Low/Medium/High — shared
// styling so it always reads the same way wherever a goal is shown.
function risk_badge_html($risk) {
    $map = [
        'low'    => ['label' => 'Low Risk',    'bg' => '#DDF5E5', 'fg' => '#1F7A3F'],
        'medium' => ['label' => 'Medium Risk', 'bg' => '#FFF3D6', 'fg' => '#8A6200'],
        'high'   => ['label' => 'High Risk',   'bg' => '#FDE0E0', 'fg' => '#B3261E'],
    ];
    if (!isset($map[$risk])) return '';
    $m = $map[$risk];
    return '<span style="display:inline-block; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:800; background:' . $m['bg'] . '; color:' . $m['fg'] . ';">' . $m['label'] . '</span>';
}

// Financial Health Score (0-100) — a single transparent composite number,
// not a trained model: each sub-score is plain arithmetic over data
// already on the Finance page, weighted the same way the project already
// blends explainable sub-scores elsewhere (see wellness_score() above).
//   30% savings consistency — % of the last 6 months that ended with
//       income >= expense (a month "in the black")
//   25% spending behaviour  — inverse of how often expenses outpaced
//       income in that window (overspending frequency)
//   25% goal progress       — average % complete across active financial
//       goals (skipped, and the other weights renormalized, if none exist)
//   20% income stability    — 100 minus the coefficient of variation of
//       monthly income (steady income = high score, spiky income = low)
// Returns null if there's no transaction history yet to score at all.
function financial_health_score($pdo, $uid, $fin_goals = null) {
    $hist = monthly_transaction_totals($pdo, $uid, 6);
    $n = count($hist['months']);
    if ($n === 0) return null;

    $in_black = 0; $overspend = 0;
    foreach ($hist['income'] as $i => $inc) {
        $exp = $hist['expense'][$i];
        if ($inc >= $exp) $in_black++;
        if ($exp > $inc) $overspend++;
    }
    $savings_consistency = round(($in_black / $n) * 100);
    $spending_behavior = round(100 - (($overspend / $n) * 100));

    $income_stability = null;
    $incomes = array_filter($hist['income'], fn($v) => $v > 0);
    if (count($incomes) >= 2) {
        $mean = array_sum($incomes) / count($incomes);
        $var = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $incomes)) / count($incomes);
        $cv = $mean > 0 ? sqrt($var) / $mean : 1;
        $income_stability = round(max(0, 100 - min(100, $cv * 100)));
    }

    $goal_progress = null;
    if ($fin_goals) {
        $pcts = [];
        foreach ($fin_goals as $g) {
            if ((float)$g['target_amount'] > 0) $pcts[] = min(100, ($g['current_amount'] / $g['target_amount']) * 100);
        }
        if (!empty($pcts)) $goal_progress = round(array_sum($pcts) / count($pcts));
    }

    $components = ['savings_consistency' => [$savings_consistency, 0.30], 'spending_behavior' => [$spending_behavior, 0.25]];
    if ($goal_progress !== null) $components['goal_progress'] = [$goal_progress, 0.25];
    if ($income_stability !== null) $components['income_stability'] = [$income_stability, 0.20];

    $weight_sum = array_sum(array_column($components, 1));
    $score = 0;
    foreach ($components as [$val, $wt]) $score += $val * ($wt / $weight_sum);

    return ['score' => (int)round($score), 'breakdown' => array_map(fn($c) => $c[0], $components)];
}

/* ============================================================
   Phase 4 — richer chart types (radar, scatter-with-trend-line,
   grouped SVG bars) and the extra queries that feed them, plus a
   shared label helper for the model forecast comparison tables.
   ============================================================ */

// Shared label for a forecasting method code — used by finance.php and
// insights.php's "why these numbers?" model comparison tables so both
// pages describe the same two candidate models (Linear Regression, ARIMA)
// the same way.
function forecast_method_label($m) {
    $map = ['arima' => 'ARIMA'];
    if (isset($map[$m])) return $map[$m];
    return $m ? ucwords(str_replace('_', ' ', $m)) : '—';
}

// Radar / spider chart — one polygon per series across N shared axes, e.g.
// comparing each category's completion rate this week vs. last week, or a
// week's productivity score shape across the seven weekdays.
// $axes = ['Label1','Label2',...] (>=3); $series = ['Name' => ['values'=>[0..$max_val,...], 'color'=>'#hex'], ...]
function svg_radar_chart($axes, $series, $max_val = 100, $size = 280) {
    $n = count($axes);
    if ($n < 3) return '<p style="color:var(--ink-soft); font-size:13px;">Need at least 3 categories to draw this chart.</p>';
    $cx = $size / 2; $cy = $size / 2;
    $r = $size / 2 - 34;
    $angle_for = fn($i) => (M_PI * 2 * $i / $n) - M_PI / 2;
    $point_for = fn($i, $val) => [
        $cx + cos($angle_for($i)) * (max(0, $val) / $max_val) * $r,
        $cy + sin($angle_for($i)) * (max(0, $val) / $max_val) * $r,
    ];

    $svg = "<svg width=\"100%\" height=\"$size\" viewBox=\"0 0 $size $size\" style=\"overflow:visible;\">";
    // concentric grid rings at 25/50/75/100%
    foreach ([0.25, 0.5, 0.75, 1.0] as $ring) {
        $pts = [];
        for ($i = 0; $i < $n; $i++) { [$x, $y] = $point_for($i, $max_val * $ring); $pts[] = "$x,$y"; }
        $svg .= '<polygon points="' . implode(' ', $pts) . '" fill="none" stroke="#EFEFEF" stroke-width="1"/>';
    }
    // axis spokes + labels
    for ($i = 0; $i < $n; $i++) {
        [$x, $y] = $point_for($i, $max_val);
        $svg .= "<line x1=\"$cx\" y1=\"$cy\" x2=\"$x\" y2=\"$y\" stroke=\"#EFEFEF\" stroke-width=\"1\"/>";
        [$lx, $ly] = $point_for($i, $max_val * 1.18);
        $cos_a = cos($angle_for($i));
        $anchor = (abs($cos_a) < 0.3) ? 'middle' : ($cos_a > 0 ? 'start' : 'end');
        $svg .= "<text x=\"$lx\" y=\"$ly\" font-size=\"10.5\" fill=\"#74747A\" text-anchor=\"$anchor\" dominant-baseline=\"middle\">" . htmlspecialchars(short_label($axes[$i], 13)) . '</text>';
    }
    // one polygon per series
    foreach ($series as $name => $s) {
        $pts = [];
        foreach ($s['values'] as $i => $v) { [$x, $y] = $point_for($i, $v); $pts[] = "$x,$y"; }
        if (count($pts) >= 3) {
            $svg .= '<polygon points="' . implode(' ', $pts) . '" fill="' . $s['color'] . '" fill-opacity="0.18" stroke="' . $s['color'] . '" stroke-width="2.5" stroke-linejoin="round"/>';
        }
        foreach ($s['values'] as $i => $v) { [$x, $y] = $point_for($i, $v); $svg .= "<circle cx=\"$x\" cy=\"$y\" r=\"3.5\" fill=\"{$s['color']}\"/>"; }
    }
    $svg .= '</svg>';

    $legend = '<div style="display:flex; gap:16px; margin-top:4px; flex-wrap:wrap;">';
    foreach ($series as $name => $s) {
        $legend .= '<span style="font-size:12px; font-weight:700; color:var(--ink-soft);"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $s['color'] . ';margin-right:6px;"></span>' . htmlspecialchars($name) . '</span>';
    }
    $legend .= '</div>';
    return $svg . $legend;
}

// Scatter plot with a fitted linear trend line and Pearson correlation
// coefficient — for "does X move with Y" views (e.g. wellness score vs.
// habit completion, focus minutes vs. completion rate) that a bar or line
// chart can't show. $points = [['x'=>float,'y'=>float,'label'=>string?], ...]
function svg_scatter_chart($points, $x_label, $y_label, $color = '#9391F5', $width = 560, $height = 240) {
    $n = count($points);
    if ($n < 3) return '<p style="color:var(--ink-soft); font-size:13px;">Need a few more data points to plot this.</p>';
    $pad_l = 40; $pad_b = 34; $pad_t = 14; $pad_r = 14;
    $plot_w = $width - $pad_l - $pad_r;
    $plot_h = $height - $pad_t - $pad_b;

    $xs = array_column($points, 'x'); $ys = array_column($points, 'y');
    $x_min = min(0, min($xs)); $x_max = max($xs) ?: 1;
    $y_min = min(0, min($ys)); $y_max = max($ys) ?: 1;
    if ($x_max <= $x_min) $x_max = $x_min + 1;
    if ($y_max <= $y_min) $y_max = $y_min + 1;
    $x_max_scaled = $x_max * 1.08; $y_max_scaled = $y_max * 1.15;

    $x_for = fn($v) => $pad_l + (($v - $x_min) / ($x_max_scaled - $x_min)) * $plot_w;
    $y_for = fn($v) => $pad_t + $plot_h - (($v - $y_min) / ($y_max_scaled - $y_min)) * $plot_h;

    // linear regression (least squares) for the trend line, plus Pearson r
    $mean_x = array_sum($xs) / $n; $mean_y = array_sum($ys) / $n;
    $num = 0; $den_x = 0; $den_y = 0;
    foreach ($points as $p) {
        $num += ($p['x'] - $mean_x) * ($p['y'] - $mean_y);
        $den_x += ($p['x'] - $mean_x) ** 2;
        $den_y += ($p['y'] - $mean_y) ** 2;
    }
    $slope = $den_x > 0 ? $num / $den_x : 0;
    $intercept = $mean_y - $slope * $mean_x;
    $r = ($den_x > 0 && $den_y > 0) ? $num / sqrt($den_x * $den_y) : 0;

    $svg = "<svg width=\"100%\" height=\"$height\" viewBox=\"0 0 $width $height\" preserveAspectRatio=\"none\" style=\"overflow:visible;\">";
    for ($g = 0; $g <= 3; $g++) {
        $gy = $pad_t + ($plot_h / 3) * $g;
        $svg .= "<line x1=\"$pad_l\" y1=\"$gy\" x2=\"" . ($width - $pad_r) . "\" y2=\"$gy\" stroke=\"#EFEFEF\" stroke-width=\"1\"/>";
    }
    // trend line, spanning the plotted x-range
    $svg .= '<line x1="' . $x_for($x_min) . '" y1="' . $y_for($slope * $x_min + $intercept) . '" x2="' . $x_for($x_max) . '" y2="' . $y_for($slope * $x_max + $intercept) . '" stroke="' . $color . '" stroke-width="2" stroke-dasharray="6,5" opacity="0.55"/>';
    // points
    foreach ($points as $p) {
        $title = !empty($p['label']) ? ('<title>' . htmlspecialchars($p['label']) . ": {$p['x']}, {$p['y']}</title>") : '';
        $svg .= '<circle cx="' . $x_for($p['x']) . '" cy="' . $y_for($p['y']) . '" r="5" fill="' . $color . '" fill-opacity="0.7" stroke="' . $color . '" stroke-width="1">' . $title . '</circle>';
    }
    $svg .= '<text x="' . $pad_l . '" y="' . ($height - 6) . '" font-size="10" fill="#74747A">' . htmlspecialchars($x_label) . ' →</text>';
    $svg .= '<text x="2" y="' . ($pad_t + 2) . '" font-size="10" fill="#74747A">↑ ' . htmlspecialchars($y_label) . '</text>';
    $svg .= '</svg>';

    $strength = abs($r) >= 0.6 ? 'strong' : (abs($r) >= 0.3 ? 'moderate' : 'weak');
    $direction = $r >= 0 ? 'positive' : 'negative';
    $note = '<div style="font-size:12px; color:var(--ink-soft); font-weight:600; margin-top:6px;">r = ' . round($r, 2) . " — a $strength $direction correlation.</div>";
    return $svg . $note;
}

// Grouped SVG bar chart — several series side by side per label (e.g. "this
// week" vs. "last week" check-ins per weekday), a step up from the plain
// single-series CSS bars used elsewhere for a direct visual comparison.
// $series = ['This week' => ['values'=>[...], 'color'=>'#hex'], ...]
function svg_grouped_bar_chart($labels, $series, $width = 640, $height = 200) {
    $pad_l = 30; $pad_b = 26; $pad_t = 14; $pad_r = 10;
    $plot_w = $width - $pad_l - $pad_r;
    $plot_h = $height - $pad_t - $pad_b;
    $n = count($labels);
    if ($n === 0 || empty($series)) return '';
    $all_vals = [1];
    foreach ($series as $s) foreach ($s['values'] as $v) $all_vals[] = $v;
    $max_v = max($all_vals) * 1.15;

    $group_w = $plot_w / $n;
    $n_series = count($series);
    $bar_gap = 4;
    $bar_w = max(4, ($group_w - $bar_gap * ($n_series + 1)) / $n_series);

    $svg = "<svg width=\"100%\" height=\"$height\" viewBox=\"0 0 $width $height\" preserveAspectRatio=\"none\" style=\"overflow:visible;\">";
    for ($g = 0; $g <= 3; $g++) {
        $gy = $pad_t + ($plot_h / 3) * $g;
        $svg .= "<line x1=\"$pad_l\" y1=\"$gy\" x2=\"" . ($width - $pad_r) . "\" y2=\"$gy\" stroke=\"#EFEFEF\" stroke-width=\"1\"/>";
    }
    for ($i = 0; $i < $n; $i++) {
        $gx = $pad_l + $i * $group_w;
        $s_idx = 0;
        foreach ($series as $name => $s) {
            $v = $s['values'][$i] ?? 0;
            $bh = $max_v > 0 ? ($v / $max_v) * $plot_h : 0;
            $bx = $gx + $bar_gap + $s_idx * ($bar_w + $bar_gap);
            $by = $pad_t + $plot_h - max(2, $bh);
            $svg .= "<rect x=\"$bx\" y=\"$by\" width=\"$bar_w\" height=\"" . max(2, $bh) . "\" rx=\"3\" fill=\"{$s['color']}\"><title>" . htmlspecialchars($name) . ' — ' . htmlspecialchars($labels[$i]) . ": $v</title></rect>";
            $s_idx++;
        }
        $svg .= '<text x="' . ($gx + $group_w / 2) . '" y="' . ($height - 6) . '" font-size="10" fill="#74747A" text-anchor="middle">' . htmlspecialchars($labels[$i]) . '</text>';
    }
    $svg .= '</svg>';

    $legend = '<div style="display:flex; gap:16px; margin-top:8px; flex-wrap:wrap;">';
    foreach ($series as $name => $s) {
        $legend .= '<span style="font-size:12px; font-weight:700; color:var(--ink-soft);"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:' . $s['color'] . ';margin-right:6px;"></span>' . htmlspecialchars($name) . '</span>';
    }
    $legend .= '</div>';
    return $svg . $legend;
}

// Last week's version of category_completion_this_week() — same shape,
// shifted back 7 days — so the two can be plotted together (radar chart,
// grouped bars) instead of only ever showing "this week" in isolation.
function category_completion_prev_week($pdo, $uid) {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name, c.color, COUNT(g.id) goal_count,
            (SELECT COUNT(*) FROM goal_logs gl JOIN goals g2 ON g2.id=gl.goal_id
             WHERE g2.category_id=c.id AND g2.user_id=? AND gl.status='done'
             AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND gl.log_date < DATE_SUB(CURDATE(), INTERVAL 6 DAY)) as done_week
         FROM categories c JOIN goals g ON g.category_id=c.id AND g.user_id=? AND g.is_active=1
         GROUP BY c.id, c.name, c.color HAVING goal_count > 0"
    );
    $stmt->execute([$uid, $uid]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $possible = $r['goal_count'] * 7;
        $r['rate_pct'] = $possible > 0 ? round(($r['done_week'] / $possible) * 100) : 0;
    }
    unset($r);
    return $rows;
}

// This-week vs. last-week check-ins per weekday (Mon-Sun) — the grouped-bar
// counterpart to weekly_checkin_bars() above, which only ever shows one week.
function weekly_checkin_bars_compare($pdo, $uid) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $active_goal_count = max(1, (int)$stmt->fetch()['c']);

    $this_dates = week_dates();
    $last_dates = array_map(fn($d) => (new DateTime($d))->modify('-7 days')->format('Y-m-d'), $this_dates);

    $stmt = $pdo->prepare("SELECT gl.log_date, COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
        WHERE g.user_id=? AND gl.status='done' AND gl.log_date BETWEEN ? AND ? GROUP BY gl.log_date");
    $stmt->execute([$uid, $last_dates[0], end($this_dates)]);
    $by_date = [];
    foreach ($stmt->fetchAll() as $r) $by_date[$r['log_date']] = (int)$r['c'];

    $labels = []; $this_week = []; $last_week = [];
    foreach ($this_dates as $i => $d) {
        $labels[] = (new DateTime($d))->format('D');
        $this_week[] = $by_date[$d] ?? 0;
        $last_week[] = $by_date[$last_dates[$i]] ?? 0;
    }
    return ['labels' => $labels, 'this_week' => $this_week, 'last_week' => $last_week, 'max' => $active_goal_count];
}

// Weekly wellness score vs. weekly habit-completion %, aligned on the same
// ISO-week buckets wellness_score_weekly() uses — for a scatter chart that
// answers "do better-wellness weeks also mean better habit consistency?"
// rather than presenting the two trends only side by side.
function wellness_completion_correlation($pdo, $uid, $weeks = 8) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $n_goals = (int)$stmt->fetch()['c'];
    if ($n_goals === 0) return [];

    $stmt = $pdo->prepare("SELECT log_date, mood, sleep_hours, stress_level FROM mood_logs
        WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY log_date");
    $stmt->execute([$uid, $weeks * 7 - 1]);
    $mood_rows = $stmt->fetchAll();
    if (empty($mood_rows)) return [];

    $stmt = $pdo->prepare("SELECT gl.log_date FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
        WHERE g.user_id=? AND gl.status='done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $stmt->execute([$uid, $weeks * 7 - 1]);
    $checkin_rows = $stmt->fetchAll();

    $mood_buckets = [];
    foreach ($mood_rows as $r) {
        $wk = (new DateTime($r['log_date']))->format('o-\WW');
        $mood_buckets[$wk][] = $r;
    }
    $checkin_buckets = [];
    foreach ($checkin_rows as $r) {
        $wk = (new DateTime($r['log_date']))->format('o-\WW');
        $checkin_buckets[$wk] = ($checkin_buckets[$wk] ?? 0) + 1;
    }
    ksort($mood_buckets);

    $points = []; $i = 1;
    foreach ($mood_buckets as $wk => $wrows) {
        $mood_avg = 0; $sleep_avg = 0; $sleep_n = 0; $stress_penalty = 0;
        foreach ($wrows as $r) {
            $mood_avg += MOOD_META[$r['mood']]['score'];
            if ($r['sleep_hours'] !== null) { $sleep_avg += (float)$r['sleep_hours']; $sleep_n++; }
            if ($r['stress_level'] === 'high') $stress_penalty += 2;
            elseif ($r['stress_level'] === 'medium') $stress_penalty += 1;
        }
        $mood_avg /= count($wrows);
        $sleep_avg = $sleep_n > 0 ? $sleep_avg / $sleep_n : 7;
        $wellness = round(($mood_avg / 5) * 60 + min($sleep_avg / 8, 1) * 30 + max(0, 10 - $stress_penalty));
        $done = $checkin_buckets[$wk] ?? 0;
        $completion = min(100, round(($done / ($n_goals * 7)) * 100));
        $points[] = ['x' => $wellness, 'y' => $completion, 'label' => 'Wk ' . $i];
        $i++;
    }
    return $points;
}

// Daily focus-session minutes vs. that day's check-in completion % over the
// last $days days — for a scatter chart answering "do longer focus days
// also mean better follow-through on habits?" Returns [] when there's no
// focus-session activity at all in the window (nothing meaningful to plot).
function focus_completion_daily($pdo, $uid, $days = 30) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM goals WHERE user_id=? AND is_active=1");
    $stmt->execute([$uid]);
    $n_goals = (int)$stmt->fetch()['c'];
    if ($n_goals === 0) return [];

    $stmt = $pdo->prepare("SELECT DATE(started_at) d, COALESCE(SUM(actual_minutes),0) m FROM focus_sessions
        WHERE user_id=? AND started_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY d");
    $stmt->execute([$uid, $days - 1]);
    $focus_by_day = [];
    foreach ($stmt->fetchAll() as $r) $focus_by_day[$r['d']] = (int)$r['m'];
    if (empty($focus_by_day)) return [];

    $stmt = $pdo->prepare("SELECT gl.log_date d, COUNT(*) c FROM goal_logs gl JOIN goals g ON g.id=gl.goal_id
        WHERE g.user_id=? AND gl.status='done' AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY d");
    $stmt->execute([$uid, $days - 1]);
    $checkins_by_day = [];
    foreach ($stmt->fetchAll() as $r) $checkins_by_day[$r['d']] = (int)$r['c'];

    $points = [];
    $cursor = new DateTime('-' . ($days - 1) . ' days');
    for ($i = 0; $i < $days; $i++) {
        $d = $cursor->format('Y-m-d');
        $mins = $focus_by_day[$d] ?? 0;
        $done = $checkins_by_day[$d] ?? 0;
        $pct = min(100, round(($done / $n_goals) * 100));
        $points[] = ['x' => $mins, 'y' => $pct, 'label' => $cursor->format('d M')];
        $cursor->modify('+1 day');
    }
    return $points;
}

/* ============================================================
   Phase 5 — IA consolidation: small shared queries so the Dashboard's
   "needs attention" list and AI Coach's insights answer the same
   question (what's pending, what streak is at risk) from ONE place
   instead of two copies of the same query.
   ============================================================ */

// Active goals not yet checked in today.
function goals_pending_today($pdo, $uid) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT g.*, c.name cat_name, c.slug cat_slug FROM goals g JOIN categories c ON c.id=g.category_id
        WHERE g.user_id=? AND g.is_active=1 AND g.id NOT IN (SELECT goal_id FROM goal_logs WHERE log_date=? AND status='done')");
    $stmt->execute([$uid, $today]);
    return $stmt->fetchAll();
}

// Of today's not-yet-done goals, the ones with a real streak (>=3 days)
// still on the line — sorted longest-streak-first.
function streaks_at_risk($pdo, $uid, $pending_today = null) {
    $pending_today = $pending_today ?? goals_pending_today($pdo, $uid);
    $at_risk = [];
    foreach ($pending_today as $g) {
        $s = current_streak($pdo, $g['id']);
        if ($s >= 3) $at_risk[] = ['goal' => $g, 'streak' => $s];
    }
    usort($at_risk, fn($a, $b) => $b['streak'] <=> $a['streak']);
    return $at_risk;
}

// A small static, rotating "quote of the day" widget — plain text,
// no external API — picked deterministically from today's date so it
// changes daily but stays stable across page loads on the same day.
function daily_quote() {
    $quotes = [
        ['text' => "Small daily improvements are the key to staggering long-term results.", 'author' => 'James Clear'],
        ['text' => "You do not rise to the level of your goals, you fall to the level of your systems.", 'author' => 'James Clear'],
        ['text' => "Discipline is choosing between what you want now and what you want most.", 'author' => 'Abraham Lincoln'],
        ['text' => "Motivation gets you going, but discipline keeps you growing.", 'author' => 'John C. Maxwell'],
        ['text' => "We are what we repeatedly do. Excellence, then, is not an act, but a habit.", 'author' => 'Will Durant'],
        ['text' => "The secret of getting ahead is getting started.", 'author' => 'Mark Twain'],
        ['text' => "Progress, not perfection.", 'author' => 'Unknown'],
        ['text' => "A year from now you may wish you had started today.", 'author' => 'Karen Lamb'],
        ['text' => "Consistency is what transforms average into excellence.", 'author' => 'Unknown'],
        ['text' => "Focus on being productive instead of busy.", 'author' => 'Tim Ferriss'],
    ];
    $idx = ((int)date('z')) % count($quotes);
    return $quotes[$idx];
}

/* ============================================================
   Habit forecasting & analytics additions — Streak Survival Curve
   and Feature Importance, shown on Insights → Forecast (Analyze
   section). Additive only: nothing above this block was changed.
   ============================================================ */

// Empirical streak-survival curve: "of every streak you've ever run
// across all your habits, what % reached at least day N" — the same
// idea as a Kaplan-Meier curve, computed directly from goal_logs
// without a stats library. A currently-still-running streak is counted
// at its length-so-far (a small simplification vs. true KM censoring,
// noted on the chart) rather than left out entirely.
function streak_survival_curve($pdo, $uid, $max_days = 21) {
    $stmt = $pdo->prepare("SELECT id FROM goals WHERE user_id=?");
    $stmt->execute([$uid]);
    $goal_ids = array_column($stmt->fetchAll(), 'id');
    if (empty($goal_ids)) return null;

    $placeholders = implode(',', array_fill(0, count($goal_ids), '?'));
    $stmt = $pdo->prepare("SELECT goal_id, log_date FROM goal_logs
        WHERE goal_id IN ($placeholders) AND status='done' ORDER BY goal_id, log_date");
    $stmt->execute($goal_ids);

    $by_goal = [];
    foreach ($stmt->fetchAll() as $r) $by_goal[$r['goal_id']][] = $r['log_date'];

    $run_lengths = [];
    foreach ($by_goal as $dates) {
        $run = 0; $prev = null;
        foreach ($dates as $d) {
            if ($prev !== null && (strtotime($d) - strtotime($prev)) / 86400 > 1) {
                $run_lengths[] = $run;
                $run = 0;
            }
            $run++;
            $prev = $d;
        }
        if ($run > 0) $run_lengths[] = $run;
    }

    if (count($run_lengths) < 3) return null;

    $total = count($run_lengths);
    $survival = [];
    for ($d = 1; $d <= $max_days; $d++) {
        $reached = 0;
        foreach ($run_lengths as $len) if ($len >= $d) $reached++;
        $survival[] = round(100 * $reached / $total);
    }
    return ['days' => range(1, $max_days), 'survival_pct' => $survival, 'n_streaks' => $total, 'longest' => max($run_lengths)];
}

// Same Pearson-r math svg_scatter_chart() already uses internally, just
// returned as a plain number instead of baked into an SVG string — lets
// the same {x,y} correlation point-sets feed a ranked "what matters most"
// bar list without duplicating a scatter chart's worth of markup.
function pearson_r($points) {
    $n = count($points);
    if ($n < 3) return null;
    $xs = array_column($points, 'x'); $ys = array_column($points, 'y');
    $mean_x = array_sum($xs) / $n; $mean_y = array_sum($ys) / $n;
    $num = 0; $den_x = 0; $den_y = 0;
    foreach ($points as $p) {
        $num += ($p['x'] - $mean_x) * ($p['y'] - $mean_y);
        $den_x += ($p['x'] - $mean_x) ** 2;
        $den_y += ($p['y'] - $mean_y) ** 2;
    }
    return ($den_x > 0 && $den_y > 0) ? $num / sqrt($den_x * $den_y) : 0;
}

// Ranks whatever correlation point-sets are available (sleep, focus time,
// ...) by |r| — the plain-arithmetic stand-in for a trained model's
// feature_importances_ (see ml/burnout_model.py's explain() for the same
// idea against a real classifier). Returns null entries filtered out, so
// a user with only one signal logged still gets a ranked list of one.
function habit_feature_importance($pdo, $uid) {
    $candidates = [
        'Sleep hours'   => wellness_completion_correlation($pdo, $uid, 8),
        'Focus minutes' => focus_completion_daily($pdo, $uid, 30),
    ];
    $ranked = [];
    foreach ($candidates as $label => $points) {
        $r = pearson_r($points);
        if ($r !== null) $ranked[] = ['label' => $label, 'r' => $r, 'abs_r' => abs($r)];
    }
    usort($ranked, fn($a, $b) => $b['abs_r'] <=> $a['abs_r']);
    return $ranked;
}
