<?php
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
// or two series. $series = ['Income' => ['actual'=>[...], 'forecast'=>[...], 'color'=>'#..'], ...]
// All series must share the same $labels (actual months/weeks + forecast months/weeks).
function svg_line_chart($labels, $series, $width = 640, $height = 200) {
    $pad_l = 46; $pad_b = 26; $pad_t = 14; $pad_r = 14;
    $plot_w = $width - $pad_l - $pad_r;
    $plot_h = $height - $pad_t - $pad_b;
    $n = count($labels);
    if ($n < 2) return '<p style="color:var(--ink-soft); font-size:13px;">Not enough data points to chart yet.</p>';

    $all_vals = [0];
    foreach ($series as $s) { foreach ($s['actual'] as $v) $all_vals[] = $v; foreach ($s['forecast'] as $v) $all_vals[] = $v; }
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
    $legend .= '<span style="font-size:11.5px; color:var(--ink-soft); margin-left:auto;">— solid = actual &nbsp; ┄ dashed = forecast</span></div>';

    return $svg . $legend;
}
