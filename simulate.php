<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/whatif.php';
require_once __DIR__ . '/includes/narrative.php';
require_once __DIR__ . '/includes/env.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'Forecast';
$nav = 'forecast';

// ============================================================
// FORECAST — "what if I changed something?"
//
// The simulations themselves are UNCHANGED. Every prediction shown here
// was worked out ahead of time by ml/whatif_engine.py and cached, and is
// read through includes/whatif.php exactly as before.
//
// What changed is the framing. The old version led with the machinery —
// which model won, its backtest accuracy, the word "lever" everywhere.
// That detail is all still here, but it now sits behind "How was this
// worked out?" instead of being the first thing a person reads. The page
// leads with the question they actually came to ask.
// ============================================================

$fin  = get_whatif($pdo, $uid, 'finance');
$hab  = get_whatif($pdo, $uid, 'habits');
$prod = get_whatif($pdo, $uid, 'productivity');
$burn = $hab['burnout_simulation'] ?? null;
$never_run = ($fin === null && $hab === null && $prod === null);

// When these scenarios were last worked out. Someone who has logged a
// fortnight of data since then is looking at a stale answer and has no
// way of knowing, so the page says so and offers to redo it.
$built_at = null;
foreach ([$fin, $hab, $prod] as $p_) {
    if (!empty($p_['_generated_at'])) {
        $t = strtotime($p_['_generated_at']);
        if ($built_at === null || $t > $built_at) $built_at = $t;
    }
}
$age_days = $built_at ? max(0, (int)floor((time() - $built_at) / 86400)) : null;
$is_stale = $age_days !== null && $age_days >= 3;

/* ---- Buy vs rent: plain arithmetic, recomputed on submit ---- */
$bvr_defaults = [
    'price' => 4500000, 'deposit_pct' => 20, 'loan_rate_pct' => 8.5,
    'loan_term_years' => 20, 'horizon_years' => 10, 'monthly_rent' => 18000,
    'rent_inflation_pct' => 5, 'appreciation_pct' => 5,
    'upkeep_pct' => 1.2, 'invest_return_pct' => 8,
];
$bvr_in = $bvr_defaults;
$bvr_submitted = isset($_POST['run_bvr']);
if ($bvr_submitted) {
    foreach ($bvr_defaults as $k => $v) {
        if (isset($_POST[$k]) && $_POST[$k] !== '') $bvr_in[$k] = (float)$_POST[$k];
    }
}
$bvr = buy_vs_rent($bvr_in);

/**
 * One simulator. The three categories are genuinely the same feature, so
 * they share a renderer rather than three look-alikes that drift apart.
 */
function sim_block(array $p, string $cat, array $copy): void {
    $lever = $p['lever'];
    $metric = $p['metric'];
    $sc = whatif_scenarios($p);
    $caveat = whatif_caveat($p);

    $js = [
        'lever' => $lever, 'metric' => $metric,
        'now' => $p['scenario_values']['expected'],
        'grid' => array_map(fn($g) => ['v' => (float)$g['value'], 'h' => (float)$g['headline']], $p['grid']),
    ];

    $labels = $p['history']['labels'];
    foreach (($p['baseline']['forecast'] ?? []) as $i => $_) $labels[] = 'Next ' . ($i + 1);
?>
<div class="lab" id="lab_<?= $cat ?>" data-sim='<?= htmlspecialchars(json_encode($js), ENT_QUOTES, 'UTF-8') ?>'>
    <p class="lab-q"><?= htmlspecialchars($copy['q']) ?></p>
    <p class="lab-note"><?= htmlspecialchars($copy['note']) ?></p>

    <div class="slider-wrap">
        <div class="slider-ends">
            <span><?= htmlspecialchars(whatif_format_lever($lever, $lever['min'])) ?></span>
            <span><?= htmlspecialchars(whatif_format_lever($lever, $lever['max'])) ?></span>
        </div>
        <input type="range" class="slide" min="<?= $lever['min'] ?>" max="<?= $lever['max'] ?>"
               step="<?= $lever['step'] ?>" value="<?= $js['now'] ?>"
               aria-label="<?= htmlspecialchars($copy['q']) ?>">
    </div>

    <div class="readout">
        <div>
            <div class="ro-l"><?= htmlspecialchars($copy['lever_label']) ?></div>
            <div class="ro-v lime js-lever"><?= htmlspecialchars(whatif_format_lever($lever, $js['now'])) ?></div>
        </div>
        <div class="ro-arrow">→</div>
        <div>
            <div class="ro-l"><?= htmlspecialchars($copy['metric_label']) ?></div>
            <div class="ro-v js-metric"><?= htmlspecialchars(nar_metric_text($metric, $sc['expected']['headline'])) ?></div>
        </div>
        <div class="ro-delta">
            <div class="ro-l">against carrying on as you are</div>
            <div class="v same js-delta">the same</div>
        </div>
    </div>
</div>

<div class="scens">
    <?php
    $tone = ['expected' => 'now', 'improved' => 'up', 'risk' => 'down'];
    $kick = ['expected' => 'As you are', 'improved' => 'If you pushed', 'risk' => 'If you slipped'];
    foreach (['expected', 'improved', 'risk'] as $k):
        if (!isset($sc[$k])) continue;
        $s = $sc[$k];
    ?>
    <article class="scen <?= $tone[$k] ?>">
        <p class="scen-k"><?= $kick[$k] ?></p>
        <p class="scen-lever"><?= htmlspecialchars($s['lever_text']) ?></p>
        <p class="scen-blurb"><?= htmlspecialchars($copy['blurb'][$k]) ?></p>
        <p class="scen-v"><?= htmlspecialchars(nar_metric_text($metric, $s['headline'])) ?></p>
        <?php if ($k === 'expected'): ?>
            <p class="scen-d">what the other two are measured against</p>
        <?php elseif ($s['same_as_expected']): ?>
            <p class="scen-d">you're already at the end of the scale here</p>
        <?php else: ?>
            <p class="scen-d">
                <?= $s['difference'] >= 0 ? '+' : '' ?><?= htmlspecialchars(nar_metric_text($metric, $s['difference'])) ?>
                <?= $s['pct_change'] !== null ? '· ' . htmlspecialchars($s['pct_text']) : '' ?>
            </p>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</div>

<?php if ($caveat): ?>
<div class="heads-up"><?= htmlspecialchars($caveat) ?></div>
<?php endif; ?>

<?php if (count($labels) >= 2): ?>
<div class="viz" style="margin-top:24px;">
    <div class="viz-top">
        <h3 class="viz-claim"><?= htmlspecialchars($copy['chart_claim']) ?></h3>
        <p class="viz-why">Your own history, and where it points if nothing changes.</p>
    </div>
    <div class="viz-body">
        <div class="chart-scroll"><?= nar_chart($labels, [
            $copy['series'] => [
                'actual' => $p['history']['values'],
                'forecast' => $p['baseline']['forecast'] ?? [],
                'color' => '#4A9A58',
            ],
        ], 640, 210) ?></div>
    </div>
</div>
<?php endif; ?>

<details class="how">
    <summary>How was this worked out?</summary>
    <ul>
        <?php
        // The assumptions are written by the simulation engine and name the
        // method in its internal code form ("arima"). Tidy those into the
        // names used everywhere else before showing them.
        $pretty = ['arima' => 'ARIMA', 'xgboost' => 'XGBoost',
                   'linear_trend' => 'Linear Regression', 'moving_average' => 'Moving Average'];
        foreach ($p['assumptions'] as $a):
            $a = str_replace(array_keys($pretty), array_values($pretty), $a); ?>
        <li><?= htmlspecialchars($a) ?></li>
        <?php endforeach; ?>
        <?php if (($p['model']['accuracy'] ?? null) !== null): ?>
        <li>Tested against your own past <?= isset($p['months_of_history']) ? 'months' : 'weeks' ?>, this got within <?= $p['model']['accuracy'] ?>% of what actually happened.</li>
        <?php endif; ?>
    </ul>
</details>

<?= ai_panel($cat) ?>
<?php
}

/** The AI explanation panel, shared by all four simulators. */
function ai_panel(string $cat): string {
    $on = hf_configured();
    ob_start(); ?>
    <div class="aip" data-ai="<?= htmlspecialchars($cat) ?>">
        <div class="aip-top">
            <div class="aip-av">✦</div>
            <p class="aip-note">
                The numbers above are already worked out. This just asks for them in plain English —
                what changed, and what it means for you.
                <?php if (!$on): ?><br><span style="color:var(--ink-soft);">Written by the app itself, since no AI key is set up.</span><?php endif; ?>
            </p>
            <button class="btn btn-go js-ai">Explain this</button>
        </div>
        <div class="aip-body" style="display:none;"></div>
    </div>
    <?php return ob_get_clean();
}

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">Forecast</p>
            <h1>What if you did something <em>differently</em>?</h1>
            <p class="hero-sub">
                Move a slider and see where your own history says you'd end up. Nothing here is a promise —
                it's what your past few months point at, with one thing changed.
            </p>
        </div>
    </div>
</header>

<div class="wrap">

<?php if ($never_run): ?>
<div class="panel" style="margin-top:40px; text-align:center; padding:48px 24px;">
    <h3 style="font-size:21px;">Nothing to simulate yet.</h3>
    <p style="color:var(--ink-mid); font-size:14.5px; margin:10px auto 0; max-width:46ch;">
        Keep logging for a couple of weeks and this unlocks on its own. If you're running this yourself,
        the simulations are built by <code>python ml/whatif_engine.py</code>.
    </p>
</div>
<?php endif; ?>

<?php if ($built_at): ?>
<div class="freshness <?= $is_stale ? 'stale' : '' ?>" id="fresh" style="margin-top:44px;">
    <div>
        <div class="freshness-t"><?php
            if ($age_days === 0)      echo 'Worked out from your data earlier today.';
            elseif ($age_days === 1)  echo 'Worked out from your data yesterday.';
            else                      echo 'Worked out from your data ' . $age_days . ' days ago.';
        ?></div>
        <div class="freshness-s">
            <?= $is_stale
                ? 'You have probably logged a fair bit since then — these may be behind.'
                : 'Anything you have logged since then is not included yet.' ?>
        </div>
    </div>
    <button class="btn <?= $is_stale ? 'btn-go' : 'btn-line' ?>" id="refreshBtn">Update now</button>
    <div class="freshness-msg" id="freshMsg" style="display:none;"></div>
</div>
<?php endif; ?>

<section class="ch enter" style="padding-top:<?= $built_at ? '18px' : '44px' ?>;">
    <div class="tabs" role="tablist">
        <button class="on" data-tab="money" role="tab">Money</button>
        <button data-tab="habits" role="tab">Habits</button>
        <button data-tab="focus" role="tab">Focus</button>
    </div>

    <!-- ---------- MONEY ---------- -->
    <div class="pane on" data-pane="money">
        <?php if (whatif_ready($fin)):
            sim_block($fin, 'finance', [
                'q' => 'What if you kept more of what you earn?',
                'note' => 'Drag to change how much of your income you hold on to, and watch what happens to next month.',
                'lever_label' => 'Keeping',
                'metric_label' => 'You would put away',
                'chart_claim' => 'What you have actually been saving, month by month.',
                'series' => 'Saved each month',
                'blurb' => [
                    'expected' => 'Carry on at the rate you are already going.',
                    'improved' => 'Tighten things up a little.',
                    'risk' => 'Let spending drift upward.',
                ],
            ]);
        else: ?>
            <div class="panel" style="text-align:center; padding:40px 24px;">
                <h3 style="font-size:19px;">Not enough money history yet.</h3>
                <p style="color:var(--ink-mid); font-size:14.5px; margin-top:8px;"><?= htmlspecialchars($fin['message'] ?? 'Log a couple of months of income and spending first.') ?></p>
            </div>
        <?php endif; ?>

        <!-- Buy vs rent -->
        <div class="ch" style="padding-top:60px;">
            <div class="ch-head"><h2>Buy or rent?</h2></div>
            <p class="ch-lead">
                This one is straight arithmetic, not a forecast — Sprout has never seen a property price, so it works
                this out the way a spreadsheet would. <strong>Every rate below is a guess you're making</strong>, and the
                answer is only ever as good as those guesses.
            </p>

            <form method="POST" class="panel" style="margin-bottom:20px;">
                <div class="bvr-grid">
                    <div class="field"><label for="b1">What the place costs (₹)</label><input id="b1" type="number" name="price" min="0" step="10000" value="<?= (int)$bvr_in['price'] ?>"></div>
                    <div class="field"><label for="b2">Deposit you'd put down (%)</label><input id="b2" type="number" name="deposit_pct" min="0" max="100" step="1" value="<?= $bvr_in['deposit_pct'] ?>"></div>
                    <div class="field"><label for="b3">Loan rate (% a year)</label><input id="b3" type="number" name="loan_rate_pct" min="0" step="0.1" value="<?= $bvr_in['loan_rate_pct'] ?>"></div>
                    <div class="field"><label for="b4">Over how many years</label><input id="b4" type="number" name="loan_term_years" min="1" max="40" step="1" value="<?= (int)$bvr_in['loan_term_years'] ?>"></div>
                    <div class="field"><label for="b5">Rent you'd pay instead (₹)</label><input id="b5" type="number" name="monthly_rent" min="0" step="500" value="<?= (int)$bvr_in['monthly_rent'] ?>"></div>
                    <div class="field"><label for="b6">Looking ahead how far (years)</label><input id="b6" type="number" name="horizon_years" min="1" max="40" step="1" value="<?= (int)$bvr_in['horizon_years'] ?>"></div>
                    <div class="field"><label for="b7">Rent rising (% a year)</label><input id="b7" type="number" name="rent_inflation_pct" step="0.1" value="<?= $bvr_in['rent_inflation_pct'] ?>"></div>
                    <div class="field"><label for="b8">Place gaining value (% a year)</label><input id="b8" type="number" name="appreciation_pct" step="0.1" value="<?= $bvr_in['appreciation_pct'] ?>"></div>
                    <div class="field"><label for="b9">Upkeep and tax (% a year)</label><input id="b9" type="number" name="upkeep_pct" min="0" step="0.1" value="<?= $bvr_in['upkeep_pct'] ?>"></div>
                    <div class="field"><label for="b10">If invested instead (% a year)</label><input id="b10" type="number" name="invest_return_pct" step="0.1" value="<?= $bvr_in['invest_return_pct'] ?>"></div>
                </div>
                <button type="submit" name="run_bvr" class="btn btn-go">Work it out</button>
                <?php if (!$bvr_submitted): ?>
                <span style="font-size:13px; color:var(--ink-soft); margin-left:12px;">These are example numbers — put yours in.</span>
                <?php endif; ?>
            </form>

            <?php $v = $bvr['verdict'];
            $better = $v['better'] === 'buy' ? 'Buying' : ($v['better'] === 'rent' ? 'Renting' : 'Neither'); ?>
            <div class="scens">
                <article class="scen now">
                    <p class="scen-k">If you bought</p>
                    <p class="scen-lever">₹<?= number_format($bvr['buy']['emi']) ?><span style="font-size:13px; font-weight:500;">/month</span></p>
                    <p class="scen-blurb">Plus ₹<?= number_format($bvr['inputs']['deposit']) ?> down and upkeep on top.</p>
                    <p class="scen-v"><?= nar_money($bvr['buy']['net_worth']) ?></p>
                    <p class="scen-d">what you'd be worth after <?= (int)$bvr['inputs']['horizon_years'] ?> years</p>
                </article>
                <article class="scen now">
                    <p class="scen-k">If you rented</p>
                    <p class="scen-lever">₹<?= number_format($bvr['rent']['first_month_rent']) ?><span style="font-size:13px; font-weight:500;">/month</span></p>
                    <p class="scen-blurb">Rising to ₹<?= number_format($bvr['rent']['last_month_rent']) ?>, with the difference invested.</p>
                    <p class="scen-v"><?= nar_money($bvr['rent']['net_worth']) ?></p>
                    <p class="scen-d">what you'd be worth after <?= (int)$bvr['inputs']['horizon_years'] ?> years</p>
                </article>
                <article class="scen <?= $v['better'] === 'buy' ? 'up' : 'down' ?>">
                    <p class="scen-k">So</p>
                    <p class="scen-lever"><?= $better ?> comes out ahead</p>
                    <p class="scen-blurb">On these assumptions, over this stretch of time.</p>
                    <p class="scen-v"><?= nar_money($v['gap']) ?></p>
                    <p class="scen-d"><?= $v['breakeven_year']
                        ? 'buying pulls ahead in year ' . $v['breakeven_year']
                        : 'buying never catches up within this window' ?></p>
                </article>
            </div>

            <?php
            $yr = array_map(fn($y) => 'Yr ' . $y['year'], $bvr['yearly']);
            if (count($yr) >= 2): ?>
            <div class="viz" style="margin-top:22px;">
                <div class="viz-top">
                    <h3 class="viz-claim">Where the two paths cross.</h3>
                    <p class="viz-why">Both lines are solid because neither is a forecast — they're both just arithmetic on your numbers.</p>
                </div>
                <div class="viz-body">
                    <div class="chart-scroll"><?= svg_line_chart($yr, [
                        'If you buy'  => ['actual' => array_column($bvr['yearly'], 'buy_net_worth'),  'forecast' => [], 'color' => '#4A9A58'],
                        'If you rent' => ['actual' => array_column($bvr['yearly'], 'rent_net_worth'), 'forecast' => [], 'color' => '#C4645A'],
                    ], 640, 220) ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ---------- HABITS ---------- -->
    <div class="pane" data-pane="habits">
        <?php if (whatif_ready($hab)):
            sim_block($hab, 'habits', [
                'q' => 'What if you showed up more often?',
                'note' => 'Drag to change how many days a week you check in, and see where next week lands.',
                'lever_label' => 'Showing up',
                'metric_label' => 'Next week you would hit',
                'chart_claim' => 'How often you have actually been checking in.',
                'series' => 'Days you showed up',
                'blurb' => [
                    'expected' => 'Keep the rhythm you already have.',
                    'improved' => 'Add a day or two a week.',
                    'risk' => 'Let a couple of days slide.',
                ],
            ]);
        else: ?>
            <div class="panel" style="text-align:center; padding:40px 24px;">
                <h3 style="font-size:19px;">Not enough check-ins yet.</h3>
                <p style="color:var(--ink-mid); font-size:14.5px; margin-top:8px;"><?= htmlspecialchars($hab['message'] ?? 'Check in for a couple of weeks first.') ?></p>
            </div>
        <?php endif; ?>

        <?php if ($burn && ($burn['status'] ?? null) === 'ok'):
            $bl = $burn['lever'];
            $bcav = whatif_caveat($burn);
            $slabels = array_map(fn($s) => $s . 'h', $burn['sleep_grid']);
        ?>
        <div class="ch" style="padding-top:60px;">
            <div class="ch-head"><h2>What if you slept more?</h2></div>
            <p class="ch-lead">
                Sprout compares your recent pattern — sleep, mood, how often you move — against thousands of other
                people's, and estimates how close it looks to burning out. It's a signal, not a diagnosis.
            </p>

            <div class="scens">
                <?php
                $bk = ['expected' => ['As you sleep now', 'Your recent average.'],
                       'improved' => ['If you slept more', 'An extra hour and a half.'],
                       'risk'     => ['If you slept less', 'An hour and a half short.']];
                $bbase = (float)$burn['scenarios']['expected']['headline'];
                foreach ($bk as $k => [$kick, $blurb]):
                    if (!isset($burn['scenarios'][$k])) continue;
                    $h = (float)$burn['scenarios'][$k]['headline'];
                    $d = round($h - $bbase, 1);
                    $tone = $k === 'expected' ? 'now' : ($d <= 0 ? 'up' : 'down');
                ?>
                <article class="scen <?= $tone ?>">
                    <p class="scen-k"><?= $kick ?></p>
                    <p class="scen-lever"><?= htmlspecialchars(whatif_format_lever($bl, $burn['scenarios'][$k]['value'])) ?></p>
                    <p class="scen-blurb"><?= $blurb ?></p>
                    <p class="scen-v"><?= $h ?>%</p>
                    <p class="scen-d"><?= $k === 'expected' ? 'where you are now' : (($d >= 0 ? '+' : '') . $d . ' points') ?></p>
                </article>
                <?php endforeach; ?>
            </div>

            <?php if ($bcav): ?><div class="heads-up"><?= htmlspecialchars($bcav) ?></div><?php endif; ?>

            <div class="viz" style="margin-top:22px;">
                <div class="viz-top">
                    <h3 class="viz-claim">How much sleep actually moves the needle for you.</h3>
                    <p class="viz-why">Lower is better. Both lines are what Sprout estimates at each amount of sleep — not a forecast over time.</p>
                </div>
                <div class="viz-body">
                    <div class="chart-scroll"><?= svg_line_chart($slabels, [
                        'On days you move'      => ['actual' => $burn['curves']['with_exercise'], 'forecast' => [], 'color' => '#4A9A58'],
                        'On days you don\'t'    => ['actual' => $burn['curves']['without_exercise'], 'forecast' => [], 'color' => '#C4645A'],
                    ], 640, 220) ?></div>
                </div>
            </div>

            <details class="how">
                <summary>How was this worked out?</summary>
                <ul><?php foreach ($burn['assumptions'] as $a): ?><li><?= htmlspecialchars($a) ?></li><?php endforeach; ?></ul>
            </details>

            <?= ai_panel('burnout') ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ---------- FOCUS ---------- -->
    <div class="pane" data-pane="focus">
        <?php if (whatif_ready($prod)):
            sim_block($prod, 'productivity', [
                'q' => 'What if you gave it a bit longer each day?',
                'note' => 'Drag to change how much focused time you put in daily, and see how the week would score.',
                'lever_label' => 'Each day',
                'metric_label' => 'Your week would score',
                'chart_claim' => 'How your weeks have actually been scoring.',
                'series' => 'Week score',
                'blurb' => [
                    'expected' => 'Keep putting in what you already do.',
                    'improved' => 'Add a little more each day.',
                    'risk' => 'Let the sessions get shorter.',
                ],
            ]);
        else: ?>
            <div class="panel" style="text-align:center; padding:40px 24px;">
                <h3 style="font-size:19px;">Not enough focused time logged yet.</h3>
                <p style="color:var(--ink-mid); font-size:14.5px; margin-top:8px;"><?= htmlspecialchars($prod['message'] ?? 'Run a few focus sessions first.') ?></p>
            </div>
        <?php endif; ?>
    </div>
</section>

</div><!-- /wrap -->

<script>
/* ------------------------------------------------------------------
   The slider only ever rests on a value that was actually simulated —
   it snaps to the nearest one rather than inventing an answer in
   between. Everything it shows was worked out in advance.
   ------------------------------------------------------------------ */
(function () {
    document.querySelectorAll('.tabs').forEach(bar => {
        bar.addEventListener('click', e => {
            const b = e.target.closest('button[data-tab]');
            if (!b) return;
            bar.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
            document.querySelectorAll('.pane').forEach(p =>
                p.classList.toggle('on', p.dataset.pane === b.dataset.tab));
        });
    });

    const money = v => {
        const a = Math.abs(v), s = v < 0 ? '-' : '';
        if (a >= 10000000) return s + '₹' + (a / 10000000).toFixed(1).replace(/\.0$/, '') + 'Cr';
        if (a >= 100000)   return s + '₹' + (a / 100000).toFixed(1).replace(/\.0$/, '') + 'L';
        if (a >= 1000)     return s + '₹' + (a / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        return s + '₹' + Math.round(a).toLocaleString();
    };
    const fmtMetric = (m, v) => (m.prefix === '₹')
        ? money(v)
        : (m.prefix || '') + Number(v).toFixed(1) + (m.suffix || '');
    const fmtLever = (l, v) => Number(v).toFixed(l.step < 1 ? 1 : 0) + (l.unit || '');

    document.querySelectorAll('.lab[data-sim]').forEach(lab => {
        const cfg = JSON.parse(lab.dataset.sim);
        const slider = lab.querySelector('.slide');
        const outL = lab.querySelector('.js-lever');
        const outM = lab.querySelector('.js-metric');
        const outD = lab.querySelector('.js-delta');
        const base = cfg.grid.find(g => Math.abs(g.v - cfg.now) < 1e-6);

        const snap = v => cfg.grid.reduce((a, b) =>
            Math.abs(b.v - v) < Math.abs(a.v - v) ? b : a, cfg.grid[0]);

        function update() {
            const g = snap(parseFloat(slider.value));
            outL.textContent = fmtLever(cfg.lever, g.v);
            outM.textContent = fmtMetric(cfg.metric, g.h);
            if (!base) return;
            const d = g.h - base.h;
            const better = cfg.metric.direction === 'lower_is_better' ? d < 0 : d > 0;
            if (Math.abs(d) < 1e-9) {
                outD.textContent = 'the same';
                outD.className = 'v same js-delta';
            } else {
                const pct = Math.abs(base.h) > 1e-9
                    ? ' (' + (d >= 0 ? '+' : '') + (d / Math.abs(base.h) * 100).toFixed(1) + '%)' : '';
                outD.textContent = (d >= 0 ? '+' : '') + fmtMetric(cfg.metric, d) + pct;
                outD.className = 'v js-delta ' + (better ? 'up' : 'down');
            }
        }
        slider.addEventListener('input', update);
        update();
    });

    /* ---- update my forecasts ----
       Runs the three Python steps in the order they depend on each
       other, so nobody has to know that order exists. */
    const rBtn = document.getElementById('refreshBtn');
    const rMsg = document.getElementById('freshMsg');
    rBtn?.addEventListener('click', async () => {
        rBtn.disabled = true;
        rBtn.innerHTML = '<span class="spin"></span> Working…';
        rMsg.style.display = 'block';
        rMsg.textContent = 'Re-reading everything you have logged. This takes a few seconds.';
        try {
            const res = await fetch('refresh.php', { method: 'POST' });
            const d = await res.json();
            if (d.ok) {
                rMsg.textContent = 'Up to date. Reloading…';
                setTimeout(() => location.reload(), 900);
            } else {
                rMsg.innerHTML = esc(d.reason || 'That did not work.') +
                    (d.manual ? '<br>You can run it yourself: <code>' + esc(d.manual) + '</code>' : '');
                rBtn.disabled = false;
                rBtn.textContent = 'Try again';
            }
        } catch (e) {
            rMsg.textContent = 'Could not reach the server. Everything on this page still stands.';
            rBtn.disabled = false;
            rBtn.textContent = 'Try again';
        }
    });

    /* ---- the written explanation ---- */
    const esc = s => String(s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    document.querySelectorAll('.aip').forEach(panel => {
        const btn = panel.querySelector('.js-ai');
        const body = panel.querySelector('.aip-body');
        const cat = panel.dataset.ai;

        btn.addEventListener('click', async () => {
            const again = btn.dataset.done === '1';
            btn.disabled = true;
            btn.textContent = 'Reading…';
            body.style.display = 'block';
            body.innerHTML = '<p style="color:var(--ink-soft); font-size:14px;">Putting it into words…</p>';

            const lab = document.getElementById('lab_' + cat);
            const params = new URLSearchParams({ category: cat });
            if (lab) params.set('lever', lab.querySelector('.slide').value);
            if (again) params.set('refresh', '1');

            try {
                const res = await fetch('ai_insight.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                });
                const d = await res.json();
                if (!d.ok) {
                    body.innerHTML = '<p class="aip-text">' + esc(d.error || 'That did not work. Try again.') + '</p>';
                } else {
                    const live = d.source === 'huggingface';
                    body.innerHTML =
                        '<span class="aip-tag' + (live ? ' live' : '') + '">' +
                        (live ? 'Written for you just now' : 'Written by Sprout') + '</span>' +
                        '<p class="aip-text">' + esc(d.text) + '</p>' +
                        (live ? '' : '<p class="aip-sub">The AI writer is not reachable right now, so Sprout wrote ' +
                            'this itself from the same numbers. Everything above is unaffected.</p>');
                }
            } catch (e) {
                body.innerHTML = '<p class="aip-text">Could not reach the server. Everything above still stands.</p>';
            } finally {
                btn.disabled = false;
                btn.dataset.done = '1';
                btn.textContent = 'Explain again';
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
