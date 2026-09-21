<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/whatif.php';
require_once __DIR__ . '/includes/narrative.php';
require_once __DIR__ . '/includes/env.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'How it works';
$nav = 'overview';

// ============================================================
// HOW IT WORKS — the one page where the machinery is allowed to show.
//
// Everywhere else in Sprout the technical detail is deliberately kept
// out of the way. Here it is the point: someone has clicked "how was
// this worked out?", and they deserve a straight answer.
//
// Everything that CAN be read from the live system IS. A transparency
// page that hardcodes its own claims isn't transparency, and it would
// drift out of date the first time a model changed.
// ============================================================

$finance_fc = get_forecast($pdo, $uid, 'finance');
$habit_fc   = get_forecast($pdo, $uid, 'habit');
$fin_wi     = get_whatif($pdo, $uid, 'finance');
$hab_wi     = get_whatif($pdo, $uid, 'habits');
$prod_wi    = get_whatif($pdo, $uid, 'productivity');

$stmt = $pdo->prepare("SELECT risk_label, generated_at FROM burnout_predictions WHERE user_id=?");
$stmt->execute([$uid]);
$burnout_row = $stmt->fetch();

/** Real files on disk, measured live — so this can never claim a dataset that isn't there. */
function dataset_facts(string $rel): ?array {
    $path = __DIR__ . '/' . $rel;
    if (!is_readable($path)) return null;
    $rows = 0;
    if (($fh = fopen($path, 'r')) !== false) {
        while (fgets($fh) !== false) $rows++;
        fclose($fh);
    }
    return ['path' => $rel, 'rows' => max(0, $rows - 1), 'size' => round(filesize($path) / 1024, 1)];
}
$ds_money   = dataset_facts('ml/data/finance_benchmark.csv');
$ds_burnout = dataset_facts('ml/data/external/modern_teen_mental_health_main.csv');

$hf_on = hf_configured();
$hf_model = env_get('HF_MODEL', 'meta-llama/Llama-3.1-8B-Instruct');

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">How it works</p>
            <h1>Where every number on this site comes from.</h1>
            <p class="hero-sub">
                Nothing here is required reading. But if you have ever wondered whether a figure was worked out
                or just made up, this is the answer — and the figures below are read from your own account, not written in by hand.
            </p>
        </div>
    </div>
</header>

<div class="wrap">

<section class="ch enter" style="padding-top:44px;">
    <div class="tabs" role="tablist">
        <button class="on" data-tab="short" role="tab">The short version</button>
        <button data-tab="data" role="tab">Whose data is whose</button>
        <button data-tab="ai" role="tab">Where AI fits</button>
        <button data-tab="ideas" role="tab">The ideas behind it</button>
    </div>

    <!-- ---------- SHORT ---------- -->
    <div class="pane on" data-pane="short">
        <div class="grid g-2">
            <article class="ins calm">
                <p class="ins-k">Step one</p>
                <h3 class="ins-h">You log things.</h3>
                <p class="ins-b">Habits you tick off, how you slept, time you focused, money in and out. That is the raw material, and it is all you.</p>
            </article>
            <article class="ins calm">
                <p class="ins-k">Step two</p>
                <h3 class="ins-h">Sprout looks for the pattern.</h3>
                <p class="ins-b">
                    It tries a few different ways of reading your history, then checks each one by hiding your recent
                    weeks and seeing which came closest to what actually happened. The winner draws the line.
                </p>
            </article>
            <article class="ins calm">
                <p class="ins-k">Step three</p>
                <h3 class="ins-h">It projects that forward.</h3>
                <p class="ins-b">
                    Not a promise — a projection, with a shaded band showing how sure it is. The band widens the
                    further ahead it reaches, because guessing next week is easier than guessing next month.
                </p>
            </article>
            <article class="ins calm">
                <p class="ins-k">Step four</p>
                <h3 class="ins-h">You change something and it runs again.</h3>
                <p class="ins-b">
                    That is the What-If. Same reading of your history, one thing altered, and you see where it lands.
                </p>
            </article>
        </div>

        <div class="panel" style="margin-top:22px;">
            <h3 style="font-size:20px; margin-bottom:6px;">How accurate is it, for you?</h3>
            <p style="color:var(--ink-mid); font-size:14.5px; margin-bottom:18px; max-width:62ch;">
                Measured by hiding your own recent periods and checking the guess against what really happened.
                These are your actual scores, not a brochure number.
            </p>
            <table class="facts">
                <tr>
                    <td>Your money</td>
                    <td><?php if ($finance_fc && ($finance_fc['status'] ?? '') === 'ok' && $finance_fc['model']['expense_accuracy'] !== null): ?>
                        Came within <strong><?= $finance_fc['model']['expense_accuracy'] ?>%</strong> of what actually happened.
                    <?php else: ?>Not enough logged months yet.<?php endif; ?></td>
                </tr>
                <tr>
                    <td>Your habits</td>
                    <td><?php if ($habit_fc && ($habit_fc['status'] ?? '') === 'ok' && $habit_fc['model']['productivity_accuracy'] !== null): ?>
                        Came within <strong><?= $habit_fc['model']['productivity_accuracy'] ?>%</strong>.
                    <?php else: ?>Not enough check-in history yet.<?php endif; ?></td>
                </tr>
                <tr>
                    <td>Burnout signal</td>
                    <td><?= $burnout_row
                        ? 'Currently reading <strong>' . htmlspecialchars($burnout_row['risk_label']) . '</strong>, last checked ' . date('j M Y', strtotime($burnout_row['generated_at'])) . '.'
                        : 'Not enough mood entries yet.' ?></td>
                </tr>
                <tr>
                    <td>What-if</td>
                    <td><?php
                        $levers = [];
                        foreach (['finance' => $fin_wi, 'habits' => $hab_wi, 'productivity' => $prod_wi] as $c => $p) {
                            if (whatif_ready($p)) $levers[] = strtolower($p['lever']['label']) . ' (' . count($p['grid']) . ' settings tried)';
                        }
                        echo $levers ? htmlspecialchars(implode(', ', $levers)) . '.' : 'Nothing simulated yet.';
                    ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- ---------- DATA ---------- -->
    <div class="pane" data-pane="data">
        <p class="ch-lead" style="margin-left:0;">
            Four different kinds of data go into this, and mixing them up is the commonest way an app like this
            ends up overstating itself. So they are kept apart, deliberately.
        </p>
        <div class="panel">
            <table class="facts">
                <tr><th>Kind</th><th>What it is</th><th>What it's for</th></tr>
                <tr>
                    <td>Other people's</td>
                    <td><?= $ds_burnout ? number_format($ds_burnout['rows']) . ' daily check-ins from a public study.' : 'A public study dataset.' ?>
                        You are not in it.</td>
                    <td>Teaching the burnout signal what a high-stress pattern tends to look like.</td>
                </tr>
                <tr>
                    <td>Yours</td>
                    <td>Everything you have logged in Sprout.</td>
                    <td>Working out your own trends and forecasts.</td>
                </tr>
                <tr>
                    <td>What gets read</td>
                    <td>Your real data, arranged the way the maths expects.</td>
                    <td>Producing the "if nothing changes" line.</td>
                </tr>
                <tr>
                    <td>What gets imagined</td>
                    <td>Your data with one thing deliberately altered. It never happened.</td>
                    <td>Producing the What-If scenarios.</td>
                </tr>
            </table>
        </div>

        <div class="heads-up" style="margin-top:20px;">
            <strong>Was your data used to train anything?</strong> No. The burnout signal learned entirely from that
            public study and is then applied to you. Your own trends are worked out from your history at the moment
            you look at them — nothing is learned from you and kept for anyone else.
        </div>

        <?php if ($ds_money): ?>
        <div class="panel" style="margin-top:20px;">
            <h3 style="font-size:19px;">One thing worth being straight about</h3>
            <p style="color:var(--ink-mid); font-size:14.5px; margin-top:10px; max-width:64ch;">
                The spending-pattern reference file bundled with Sprout
                (<?= number_format($ds_money['rows']) ?> rows) is <strong>synthetic</strong> — generated by a script, not
                collected from real people. It has a realistic shape, and it only ever fills in typical category
                proportions before you have logged enough of your own. It never touches your actual income or
                spending forecast.
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ---------- AI ---------- -->
    <div class="pane" data-pane="ai">
        <p class="ch-lead" style="margin-left:0;">
            There are two very different things in Sprout that get called "AI", and only one of them ever produces a number.
        </p>
        <div class="panel">
            <table class="facts">
                <tr><th></th><th>The maths</th><th>The writing</th></tr>
                <tr><td>What it does</td><td>Works out every figure you see.</td><td>Turns those figures into sentences.</td></tr>
                <tr><td>What it's given</td><td>Your logged history, as numbers.</td><td>About twenty already-finished figures.</td></tr>
                <tr><td>What it returns</td><td>A projection, plus how wrong it tends to be.</td><td>A few sentences. <strong>No numbers of its own.</strong></td></tr>
                <tr><td>Where it runs</td><td>On this machine, ahead of time.</td><td><?= $hf_on ? 'A writing service, when you ask for it.' : 'Currently your own machine, since no key is set up.' ?></td></tr>
                <tr><td>If it breaks</td><td>There is nothing to show, and Sprout says so.</td><td>Nothing breaks. Sprout writes it instead and labels it.</td></tr>
            </table>
        </div>

        <div class="heads-up" style="margin-top:20px;">
            <strong>Why not let the writer do the predicting too?</strong> Because it would be guessing. It cannot see
            your transaction history, cannot fit a trend to it, and cannot tell you how wrong it is likely to be. The
            maths can do all three — every projection here has been tested against your own past. A language model
            asked for "next month's spending" would produce something that merely sounds right.
        </div>

        <p style="font-size:13px; color:var(--ink-soft); margin-top:18px;">
            <?= $hf_on ? 'Currently writing with ' . htmlspecialchars($hf_model) . '.' : 'No writing service configured — explanations come from Sprout itself.' ?>
        </p>
    </div>

    <!-- ---------- IDEAS ---------- -->
    <div class="pane" data-pane="ideas">
        <p class="ch-lead" style="margin-left:0;">
            The ideas Sprout genuinely uses. Things it does not use — neural networks, deep learning, anything that
            sounds impressive but isn't in here — are left off on purpose.
        </p>
        <div class="grid g-2">
        <?php
        $ideas = [
            ['Learning from data', 'Finding patterns in numbers rather than being told rules for every case.', 'Spending and habits are far too irregular to write rules for by hand.'],
            ['Forecasting', 'Predicting a future point in something that changes over time.', 'Last month genuinely tells you something about next month, and ignoring that order throws information away.'],
            ['Checking the work', 'Hiding recent history, predicting it, and comparing the guess to what really happened.', 'Without it, "Sprout predicts X" would be an unfalsifiable claim. With it, every projection carries a measured error.'],
            ['Honest uncertainty', 'A shaded band around a projection instead of a single confident number.', 'A lone number implies a precision that no forecast has.'],
            ['What-if analysis', 'Asking what the same reading of your history would say if one input were different.', 'A forecast tells you where you are heading. This tells you which lever is worth pulling.'],
            ['Correlation', 'Measuring how strongly two of your habits move together.', 'It shows which behaviours travel in pairs — while being careful never to claim one causes the other.'],
            ['Cold starts', 'Filling gaps with typical values until you have logged enough of your own.', 'A brand-new account has nothing to learn from, and a blank screen helps nobody.'],
            ['Failing softly', 'When a piece breaks, the rest carries on at reduced capability.', 'The writing service depends on a third party and an internet connection, both of which will eventually fail.'],
        ];
        foreach ($ideas as [$name, $what, $why]): ?>
            <article class="ins calm">
                <p class="ins-k"><?= htmlspecialchars($name) ?></p>
                <h3 class="ins-h" style="font-size:17px;"><?= htmlspecialchars($what) ?></h3>
                <p class="ins-b"><?= htmlspecialchars($why) ?></p>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
</section>

</div>

<script>
document.querySelectorAll('.tabs').forEach(bar => {
    bar.addEventListener('click', e => {
        const b = e.target.closest('button[data-tab]');
        if (!b) return;
        bar.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
        document.querySelectorAll('.pane').forEach(p =>
            p.classList.toggle('on', p.dataset.pane === b.dataset.tab));
    });
});
</script>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
