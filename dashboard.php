<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/narrative.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'Overview';
$nav = 'overview';

// ============================================================
// OVERVIEW — the narrative home.
//
// Every number below comes from the same helpers and the same cached
// predictions the app already had. Nothing was recalculated for this
// redesign; includes/narrative.php only decides what is worth saying and
// puts it into plain English.
//
// The page is a sequence, not a grid of charts:
//   01 where you are · 02 what's changing · 03 what's likely next ·
//   04 why it matters · 05 what you can do · 06 what you're becoming
// Each chart appears only underneath a claim it is evidence for.
// ============================================================

evaluate_achievements($pdo, $uid);

$today   = nar_today($pdo, $uid);
$changes = nar_changes($pdo, $uid, 4);
$next    = nar_next($pdo, $uid);
$stakes  = nar_stakes($pdo, $uid);
$actions = nar_actions($pdo, $uid, 3);

// The What-If strip reads the simulations that already exist — the same
// cached numbers the Forecast page shows. Nothing is recomputed here.
$whatifs = [];
$wi_copy = [
    'finance'      => ['q' => 'What if you kept a little more of what you earn?', 'tab' => 'money'],
    'habits'       => ['q' => 'What if you showed up one more day a week?',        'tab' => 'habits'],
    'productivity' => ['q' => 'What if you gave it longer each day?',              'tab' => 'focus'],
];
foreach ($wi_copy as $cat => $copy) {
    $w = get_whatif($pdo, $uid, $cat);
    if (!whatif_ready($w)) continue;
    $sc = whatif_scenarios($w);
    if (empty($sc['improved']) || $sc['improved']['same_as_expected']) continue;
    if (($sc['improved']['difference'] ?? null) === null) continue;
    $whatifs[] = [
        'q'    => $copy['q'],
        'from' => nar_metric_text($w['metric'], $sc['expected']['headline']),
        'to'   => nar_metric_text($w['metric'], $sc['improved']['headline']),
        'diff' => nar_metric_text($w['metric'], abs($sc['improved']['difference'])),
        'lever'=> $sc['improved']['lever_text'],
    ];
}
$growth  = nar_growth($pdo, $uid);

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$first = htmlspecialchars(explode(' ', trim($user['full_name']))[0]);

require_once __DIR__ . '/includes/shell.php';
?>

<!-- ============================================================
     HERO — deep forest. The plant's ground, and the first thing seen.
     ============================================================ -->
<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi"><?= $greeting ?>, <?= $first ?></p>
            <h1><?= htmlspecialchars($today['headline']) ?></h1>
            <p class="hero-sub"><?= htmlspecialchars($today['sub']) ?></p>
        </div>
        <div class="hero-stats">
            <div>
                <div class="hstat-n"><span data-count="<?= $growth['current_streak'] ?>"><?= $growth['current_streak'] ?></span></div>
                <div class="hstat-l">day run<?= $growth['current_streak_title'] ? ' on ' . htmlspecialchars($growth['current_streak_title']) : '' ?></div>
            </div>
            <div>
                <div class="hstat-n"><span data-count="<?= $growth['checkins'] ?>"><?= number_format($growth['checkins']) ?></span></div>
                <div class="hstat-l">times you've shown up</div>
            </div>
            <div>
                <div class="hstat-n"><?= htmlspecialchars($growth['stage_name']) ?></div>
                <div class="hstat-l">where your sprout is</div>
            </div>
        </div>
    </div>
</header>

<div class="wrap">

<!-- ============================================================
     01 — WHERE YOU ARE
     ============================================================ -->
<section class="ch enter">
    <div class="ch-head"><span class="ch-n">01</span><h2>Where you are</h2></div>
    <p class="ch-lead">Today, at a glance. Tick things off here and everything else on this page updates with it.</p>

    <div class="grid g-hero">
        <div class="panel">
            <?php if (empty($today['pending']) && $today['total'] > 0): ?>
                <div style="text-align:center; padding:34px 10px;">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--shoot-deep)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="margin-bottom:12px;"><path d="M12 21V9.5M12 9.5C12 6 9.5 3.5 5.5 3.5c0 4 2.5 6 6.5 6Zm0 0c0-3 2-5 5.5-5 0 3.5-2 5-5.5 5Z"/></svg>
                    <h3 style="font-size:21px;">That's the lot for today.</h3>
                    <p style="color:var(--ink-mid); font-size:14.5px; margin-top:8px;">Come back tomorrow — or get ahead on Habits.</p>
                </div>
            <?php elseif ($today['total'] === 0): ?>
                <div style="text-align:center; padding:30px 10px;">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--shoot-deep)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="margin-bottom:12px;"><path d="M12 21v-7m0 0c0-3.3-2.3-5.5-6-5.5 0 3.7 2.3 5.5 6 5.5Z"/></svg>
                    <h3 style="font-size:21px;">Nothing planted yet.</h3>
                    <p style="color:var(--ink-mid); font-size:14.5px; margin:8px 0 20px;">Add one habit you'd like to keep. One is enough to start.</p>
                    <a href="habits.php" class="btn btn-go">Add a habit</a>
                </div>
            <?php else: ?>
                <div class="todo">
                    <?php foreach ($today['pending'] as $g):
                        $streak = $today['streak_by_goal'][$g['id']] ?? 0; ?>
                    <div class="todo-row">
                        <div>
                            <div class="todo-t"><?= htmlspecialchars($g['title']) ?></div>
                            <div class="todo-m">
                                <?= htmlspecialchars($g['cat_name']) ?><?php if ($streak): ?>
                                <span class="risk">&nbsp;· <?= nar_plural($streak, 'day') ?> at stake</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button class="tick day-box" data-goal="<?= $g['id'] ?>" data-date="<?= date('Y-m-d') ?>">
                            <span class="t-off">Check in</span><span class="t-on">Done</span>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel" style="display:flex; align-items:center; justify-content:center; gap:26px;">
            <?php
            $circ = 2 * M_PI * 64;
            $off  = $circ - ($today['pct'] / 100) * $circ;
            ?>
            <div class="ring">
                <svg width="150" height="150" viewBox="0 0 150 150">
                    <circle class="bg" cx="75" cy="75" r="64"></circle>
                    <circle class="fg" cx="75" cy="75" r="64"
                            stroke-dasharray="<?= $circ ?>" data-offset="<?= $off ?>"></circle>
                </svg>
                <div class="ring-mid">
                    <div class="ring-n"><span data-count="<?= $today['pct'] ?>" data-post="%"><?= $today['pct'] ?>%</span></div>
                    <div class="ring-l"><?= $today['done'] ?> of <?= $today['total'] ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     02 — WHAT'S CHANGING
     ============================================================ -->
<?php if ($changes): ?>
<section class="ch enter">
    <div class="ch-head"><span class="ch-n">02</span><h2>What's changing</h2></div>
    <p class="ch-lead">The things that moved recently — not everything, just what's actually worth knowing.</p>

    <div class="grid g-2">
        <?php foreach ($changes as $i => $c): ?>
        <article class="ins <?= $c['tone'] ?> enter" data-delay="<?= $i * 70 ?>">
            <p class="ins-k"><?= htmlspecialchars($c['kicker']) ?></p>
            <h3 class="ins-h"><?= htmlspecialchars($c['headline']) ?></h3>
            <p class="ins-b"><?= htmlspecialchars($c['body']) ?></p>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ============================================================
     03 — WHAT'S LIKELY NEXT
     Held back behind an invitation. Curiosity, not concealment: the
     button says exactly what it will show.
     ============================================================ -->
<section class="ch enter">
    <div class="ch-head"><span class="ch-n">03</span><h2>What's likely next</h2></div>
    <p class="ch-lead">Where your recent weeks point, if things carry on roughly as they have been.</p>

    <?php if (!$next['habit'] && !$next['money']): ?>
        <div class="panel" style="text-align:center; padding:40px 24px;">
            <h3 style="font-size:20px;">Not enough history yet.</h3>
            <p style="color:var(--ink-mid); font-size:14.5px; margin-top:8px; max-width:44ch; margin-inline:auto;">
                Keep checking in for a couple of weeks and Sprout will start showing you what's coming.
            </p>
        </div>
    <?php else: ?>
        <div id="tease" class="tease">
            <h3 class="tease-h">Something's taking shape in your next few weeks.</h3>
            <p class="tease-b">Have a look at where your current rhythm is heading.</p>
            <button class="btn btn-go" id="revealBtn" style="margin-top:20px;">Show me what's next</button>
        </div>

        <div id="reveal" hidden>
            <div class="grid" style="gap:20px;">
            <?php if ($next['habit']): $n = $next['habit'];
                $labels = [];
                foreach ($n['history'] as $i => $_) $labels[] = 'Wk ' . ($i + 1);
                foreach ($n['forecast'] as $i => $_) $labels[] = 'Next ' . ($i + 1);
            ?>
                <article class="viz">
                    <div class="viz-top">
                        <h3 class="viz-claim"><?= htmlspecialchars($n['claim']) ?></h3>
                        <p class="viz-why"><?= htmlspecialchars($n['why']) ?></p>
                    </div>
                    <div class="viz-body">
                        <div class="chart-scroll"><?= nar_chart($labels, [
                            'How often you check in' => [
                                'actual' => $n['history'], 'forecast' => $n['forecast'],
                                'color' => '#4A9A58',
                                'ci_lower' => $n['lower'], 'ci_upper' => $n['upper'],
                            ],
                        ], 640, 210) ?></div>
                    </div>
                    <div style="padding:0 30px;"><div class="viz-foot">
                        <svg class="viz-foot-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 21V9.5M12 9.5C12 6 9.5 3.5 5.5 3.5c0 4 2.5 6 6.5 6Z"/>
                        </svg>
                        <p class="viz-foot-t"><b>What this means.</b> <?= htmlspecialchars($n['take']) ?>
                        <?php if (!empty($n['try'])): ?><br><b>Try this.</b> <?= htmlspecialchars($n['try']) ?><?php endif; ?></p>
                    </div></div>
                </article>
            <?php endif; ?>

            <?php if ($next['money']): $m = $next['money'];
                $mlabels = $m['months'];
                foreach ($m['f_income'] as $i => $_) $mlabels[] = 'Next ' . ($i + 1);
            ?>
                <article class="viz">
                    <div class="viz-top">
                        <h3 class="viz-claim"><?= htmlspecialchars($m['claim']) ?></h3>
                        <p class="viz-why"><?= htmlspecialchars($m['why']) ?></p>
                    </div>
                    <div class="viz-body">
                        <div class="chart-scroll"><?= nar_chart($mlabels, [
                            'Money in'  => ['actual' => $m['income'],  'forecast' => $m['f_income'],  'color' => '#4A9A58'],
                            'Money out' => ['actual' => $m['expense'], 'forecast' => $m['f_expense'], 'color' => '#C4645A'],
                        ], 640, 210) ?></div>
                    </div>
                    <div style="padding:0 30px;"><div class="viz-foot">
                        <svg class="viz-foot-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 21V9.5M12 9.5C12 6 9.5 3.5 5.5 3.5c0 4 2.5 6 6.5 6Z"/>
                        </svg>
                        <p class="viz-foot-t"><b>What this means.</b> <?= htmlspecialchars($m['take']) ?>
                        <?php if (!empty($m['try'])): ?><br><b>Try this.</b> <?= htmlspecialchars($m['try']) ?><?php endif; ?></p>
                    </div></div>
                </article>
            <?php endif; ?>
            </div>

            <?php if ($stakes): ?>
            <p style="font-size:15px; color:var(--ink-mid); margin:30px 0 14px; max-width:60ch;">
                Which matters because, stretched out over a year, it looks like this.
            </p>
            <div class="stakes">
                <?php foreach ($stakes as $st): ?>
                <div class="stake">
                    <div class="stat-n"><?= htmlspecialchars($st['n']) ?></div>
                    <div class="stat-l"><?= htmlspecialchars($st['l']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ============================================================
     04 — WHAT IF?
     The existing simulations, framed as the question they answer.
     Every figure here was worked out in advance by the simulator.
     ============================================================ -->
<?php if ($whatifs || $actions): ?>
<section class="ch enter">
    <div class="ch-head"><span class="ch-n">04</span><h2>What could change it</h2></div>
    <p class="ch-lead">You are not stuck with the line above. Here is what moving one thing would actually do.</p>

    <?php if ($whatifs): ?>
    <div class="whatifs">
        <?php foreach ($whatifs as $w): ?>
        <a class="wi" href="simulate.php">
            <p class="wi-q"><?= htmlspecialchars($w['q']) ?></p>
            <div class="wi-move">
                <span class="wi-from"><?= htmlspecialchars($w['from']) ?></span>
                <span class="wi-to"><?= htmlspecialchars($w['to']) ?></span>
            </div>
            <p class="wi-d">A difference of <?= htmlspecialchars($w['diff']) ?>, at <?= htmlspecialchars($w['lever']) ?>.</p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($actions): ?>
    <div class="acts" style="margin-top:<?= $whatifs ? '20px' : '0' ?>;">
        <?php foreach ($actions as $i => $a): ?>
        <a class="act" href="<?= htmlspecialchars($a['href']) ?>">
            <span class="act-i"><?= $i + 1 ?></span>
            <span>
                <span class="act-do"><?= htmlspecialchars($a['do']) ?></span>
                <span class="act-why"><?= htmlspecialchars($a['why']) ?></span>
            </span>
            <svg class="act-go" width="19" height="19" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h13m-5-6 6 6-6 6"/>
            </svg>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p style="margin-top:20px;"><a class="more" href="simulate.php">Try your own numbers</a></p>
</section>
<?php endif; ?>

<!-- ============================================================
     05 — ASK SPROUT
     Routes into the existing conversation rather than duplicating it.
     ============================================================ -->
<section class="ch enter">
    <div class="ch-head"><span class="ch-n">05</span><h2>Ask about any of it</h2></div>
    <p class="ch-lead">Anything above that you want explained, or anything you would just rather ask outright.</p>

    <div class="askbox">
        <h3>What would you like to know?</h3>
        <p>Sprout can only see what you have logged, and will tell you when it does not have something rather than guess.</p>

        <div class="askbox-chips">
            <?php
            $chips = ["Why did my spending change this month?", "What's affecting my productivity?",
                      "Can I reach my savings goal?", "What should I change?"];
            foreach ($chips as $c): ?>
            <a href="coach.php?q=<?= urlencode($c) ?>"><?= htmlspecialchars($c) ?></a>
            <?php endforeach; ?>
        </div>

        <form class="askbox-form" action="coach.php" method="GET">
            <input type="text" name="q" placeholder="Or ask in your own words…" aria-label="Ask Sprout a question">
            <button type="submit" class="btn btn-dark">Ask</button>
        </form>
    </div>
</section>

<!-- ============================================================
     06 — SEE YOUR GROWTH
     The finale, and the one place the metaphor is made literal. The
     plant's height and leaf count come from real check-in milestones.
     ============================================================ -->
<section class="ch enter">
    <div class="ch-head"><span class="ch-n">06</span><h2>See your growth</h2></div>
    <p class="ch-lead">Everything above, compounded. This is what all those small decisions have added up to.</p>

    <div class="growth" data-grow>
        <div><?= nar_sprout_svg($growth['stage'], 190, 250) ?></div>
        <div>
            <span class="stage">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <circle cx="12" cy="12" r="5"/>
                </svg>
                <?= htmlspecialchars($growth['stage_name']) ?>
            </span>
            <h2><?= htmlspecialchars($growth['stage_line']) ?></h2>

            <?php if ($growth['next_name']): ?>
            <p class="growth-lead">
                <?= nar_plural($growth['to_next'], 'more check-in') ?> and your sprout reaches
                <strong style="color:var(--lime);"><?= htmlspecialchars($growth['next_name']) ?></strong>.
            </p>
            <div class="bar" style="--w:<?= $growth['pct_to_next'] ?>%"><i></i></div>
            <?php else: ?>
            <p class="growth-lead">You've reached the last stage. This is what consistency looks like from the outside.</p>
            <?php endif; ?>

            <div class="milestones">
                <div>
                    <div class="ms-n"><span data-count="<?= $growth['best_ever'] ?>"><?= $growth['best_ever'] ?></span></div>
                    <div class="ms-l">longest run ever</div>
                </div>
                <div>
                    <div class="ms-n"><span data-count="<?= $growth['level'] ?>"><?= $growth['level'] ?></span></div>
                    <div class="ms-l">level reached</div>
                </div>
                <div>
                    <div class="ms-n"><span data-count="<?= $growth['checkins'] ?>"><?= number_format($growth['checkins']) ?></span></div>
                    <div class="ms-l">total check-ins</div>
                </div>
            </div>
        </div>
    </div>
</section>

</div><!-- /wrap -->

<script>
/* The forecast reveal. The teaser is replaced by the real thing on
   click — motion that answers an action, showing what changed. */
(function () {
    const btn = document.getElementById('revealBtn');
    const tease = document.getElementById('tease');
    const panel = document.getElementById('reveal');
    if (!btn || !panel) return;
    btn.addEventListener('click', () => {
        tease.style.display = 'none';
        panel.hidden = false;
        panel.classList.add('revealed');
        panel.querySelectorAll('[data-count]').forEach(el => el.dispatchEvent(new Event('sprout:count')));
    });
})();
</script>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
