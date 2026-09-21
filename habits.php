<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/narrative.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'Habits';
$nav = 'habits';

// ============================================================
// HABITS — one place to keep the streak alive.
//
// Under the new six-section structure this is also where mood lives, so
// the whole "did I look after myself today" question is answered in one
// screen instead of two.
//
// The POST handlers below are UNCHANGED from the previous version of this
// page (add goal, delete goal) plus the mood handler lifted verbatim from
// mood.php. Check-ins still go through toggle_log.php via js/app.js.
// Nothing about how data is written has changed — only how it looks.
// ============================================================

$categories = get_all_categories($pdo);
$filter = $_GET['cat'] ?? 'all';

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
    header('Location: habits.php?cat=' . urlencode($filter) . '&added=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_goal'])) {
    $stmt = $pdo->prepare("DELETE FROM goals WHERE id=? AND user_id=?");
    $stmt->execute([(int)$_POST['goal_id'], $uid]);
    header('Location: habits.php?cat=' . urlencode($filter));
    exit;
}

// Mood — same insert/update as mood.php, so one day still means one row.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mood'])) {
    $mood = $_POST['mood'] ?? '';
    if (array_key_exists($mood, MOOD_META)) {
        $stmt = $pdo->prepare("INSERT INTO mood_logs (user_id, log_date, mood)
            VALUES (?, CURDATE(), ?)
            ON DUPLICATE KEY UPDATE mood=VALUES(mood)");
        $stmt->execute([$uid, $mood]);
        evaluate_achievements($pdo, $uid);
    }
    header('Location: habits.php?cat=' . urlencode($filter) . '&mood=1');
    exit;
}

$query = "SELECT g.*, c.slug AS cat_slug, c.name AS cat_name FROM goals g JOIN categories c ON c.id=g.category_id WHERE g.user_id=? AND g.is_active=1";
$params = [$uid];
if ($filter !== 'all') { $query .= " AND c.slug=?"; $params[] = $filter; }
$query .= " ORDER BY g.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$goals = $stmt->fetchAll();

$dates = week_dates();
$day_labels = ['M','T','W','T','F','S','S'];
$today_str = date('Y-m-d');

$today = nar_today($pdo, $uid);
$growth = nar_growth($pdo, $uid);
$mood_today = todays_mood($pdo, $uid);

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">Habits</p>
            <h1><?= $today['total'] > 0
                ? htmlspecialchars($today['headline'])
                : 'Pick something small.' ?></h1>
            <p class="hero-sub"><?= $today['total'] > 0
                ? 'Tick a day to check in. Miss one and the run resets — that is the whole game.'
                : 'One habit, done most days, beats five you abandon in a fortnight.' ?></p>
        </div>
        <div class="hero-stats">
            <div>
                <div class="hstat-n"><span data-count="<?= $growth['current_streak'] ?>"><?= $growth['current_streak'] ?></span></div>
                <div class="hstat-l">day run right now</div>
            </div>
            <div>
                <div class="hstat-n"><span data-count="<?= $growth['best_ever'] ?>"><?= $growth['best_ever'] ?></span></div>
                <div class="hstat-l">your record</div>
            </div>
        </div>
    </div>
</header>

<div class="wrap">

<?php if (isset($_GET['added'])): ?>
<div class="flash" style="margin-top:34px;">Habit added. It starts counting from today.</div>
<?php elseif (isset($_GET['mood'])): ?>
<div class="flash" style="margin-top:34px;">Noted — thanks for checking in with yourself.</div>
<?php endif; ?>

<!-- ---------- Mood ---------- -->
<section class="ch enter" style="padding-top:<?= isset($_GET['added']) || isset($_GET['mood']) ? '28px' : '56px' ?>;">
    <div class="ch-head"><h2>How's today going?</h2></div>
    <p class="ch-lead">
        <?= $mood_today
            ? 'You logged <strong>' . htmlspecialchars(MOOD_META[$mood_today['mood']]['label']) . '</strong> today. Change it if things have shifted.'
            : 'One tap. It turns out how you feel and whether you show up are closely linked.' ?>
    </p>
    <form method="POST" class="mood">
        <?php foreach (MOOD_META as $key => $m): ?>
        <button type="submit" name="mood" value="<?= $key ?>"
                class="<?= $mood_today && $mood_today['mood'] === $key ? 'on' : '' ?>">
            <span class="em"><?= $m['emoji'] ?></span>
            <span class="lb"><?= $m['label'] ?></span>
        </button>
        <?php endforeach; ?>
        <input type="hidden" name="save_mood" value="1">
    </form>
</section>

<!-- ---------- The habits ---------- -->
<section class="ch enter">
    <div class="ch-head" style="justify-content:space-between; width:100%;">
        <h2>Your habits</h2>
        <button class="btn btn-go" onclick="document.getElementById('addSheet').classList.add('show')">Add a habit</button>
    </div>
    <p class="ch-lead">This week, day by day. Green means done.</p>

    <div class="chips">
        <a class="chip <?= $filter === 'all' ? 'on' : '' ?>" href="habits.php?cat=all">Everything</a>
        <?php foreach ($categories as $c): ?>
        <a class="chip <?= $filter === $c['slug'] ? 'on' : '' ?>" href="habits.php?cat=<?= $c['slug'] ?>">
            <?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($goals)): ?>
        <div class="panel" style="text-align:center; padding:48px 24px;">
            <h3 style="font-size:21px;"><?= $filter === 'all' ? 'Nothing planted yet.' : 'Nothing here yet.' ?></h3>
            <p style="color:var(--ink-mid); font-size:14.5px; margin:10px auto 22px; max-width:42ch;">
                <?= $filter === 'all'
                    ? 'Add one habit you would like to keep. Starting with a single easy one works better than starting with five.'
                    : 'No habits in this category. Add one, or look at everything instead.' ?>
            </p>
            <button class="btn btn-go" onclick="document.getElementById('addSheet').classList.add('show')">Add a habit</button>
        </div>
    <?php else: ?>
    <div class="habits">
        <?php foreach ($goals as $g):
            $pct = week_percent($pdo, $g['id']);
            $circ = 2 * M_PI * 24;
            $off = $circ - ($pct / 100) * $circ;
            $logged = array_flip(week_logs($pdo, $g['id']));
            $streak = current_streak($pdo, $g['id']);
            $at_risk = $streak >= 3 && !isset($logged[$today_str]);
        ?>
        <article class="habit <?= $at_risk ? 'risk' : '' ?>">
            <div class="hring">
                <svg width="58" height="58" viewBox="0 0 58 58">
                    <circle class="bg" cx="29" cy="29" r="24"></circle>
                    <circle class="fg" cx="29" cy="29" r="24"
                            stroke-dasharray="<?= $circ ?>" stroke-dashoffset="<?= $off ?>"></circle>
                </svg>
                <span><?= $pct ?>%</span>
            </div>

            <div>
                <h3><?= htmlspecialchars($g['title']) ?></h3>
                <div class="habit-meta">
                    <span><?= htmlspecialchars($g['cat_name']) ?></span>
                    <?php if ($streak > 0): ?>
                        <span class="<?= $at_risk ? 'fire' : '' ?>">
                            <?= $streak ?>-day run<?= $at_risk ? ' · not ticked yet' : '' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="week">
                <?php foreach ($dates as $i => $d):
                    $future = $d > $today_str;
                    $done = isset($logged[$d]);
                ?>
                <div class="wd day-box <?= $future ? 'future' : ($done ? 'done' : '') ?> <?= $d === $today_str ? 'is-today' : '' ?>"
                     data-date="<?= $d ?>" data-goal="<?= $g['id'] ?>"
                     title="<?= date('D j M', strtotime($d)) ?>"><?= $day_labels[$i] ?></div>
                <?php endforeach; ?>
            </div>

            <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($g['title'])) ?> and its whole history? This cannot be undone.');">
                <input type="hidden" name="goal_id" value="<?= $g['id'] ?>">
                <button type="submit" name="delete_goal" class="kill" title="Delete this habit" aria-label="Delete <?= htmlspecialchars($g['title']) ?>">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"/>
                    </svg>
                </button>
            </form>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

</div><!-- /wrap -->

<!-- ---------- Add habit ---------- -->
<div class="veil" id="addSheet">
    <div class="sheet">
        <h2>A new habit</h2>
        <p class="note">Keep it small enough that a bad day can't stop you.</p>
        <form method="POST">
            <div class="field">
                <label for="h-title">What will you do?</label>
                <input id="h-title" type="text" name="title" placeholder="Read for 20 minutes" required>
            </div>
            <div class="field">
                <label for="h-desc">Any detail (optional)</label>
                <input id="h-desc" type="text" name="description" placeholder="Fiction, before bed">
            </div>
            <div class="field">
                <label for="h-cat">Which part of your life?</label>
                <select id="h-cat" name="category_id" required>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter === $c['slug'] ? 'selected' : '' ?>>
                        <?= $c['icon'] ?> <?= htmlspecialchars($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row2">
                <div class="field">
                    <label for="h-freq">How often</label>
                    <select id="h-freq" name="frequency">
                        <option value="daily">Every day</option>
                        <option value="weekly">Some days</option>
                    </select>
                </div>
                <div class="field">
                    <label for="h-target">Days a week</label>
                    <input id="h-target" type="number" name="target_per_week" min="1" max="7" value="7">
                </div>
            </div>
            <div class="field">
                <label for="h-mins">Roughly how long, in minutes</label>
                <input id="h-mins" type="number" name="est_minutes" min="1" max="240" value="20">
            </div>
            <div class="sheet-foot">
                <button type="button" class="btn btn-line" onclick="document.getElementById('addSheet').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_goal" class="btn btn-go">Add habit</button>
            </div>
        </form>
    </div>
</div>

<script>
/* Close the sheet on the backdrop or Escape — the two things people try. */
(function () {
    const veil = document.getElementById('addSheet');
    if (!veil) return;
    veil.addEventListener('click', e => { if (e.target === veil) veil.classList.remove('show'); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') veil.classList.remove('show'); });
})();
</script>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
