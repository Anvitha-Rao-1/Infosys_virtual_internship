<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/narrative.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Finance';
$nav = 'finance';

// ============================================================
// FINANCE — log what happened, see where it's heading, save toward
// something.
//
// Under the six-section structure this page absorbs what used to be
// split between finance.php (entry) and analyse.php's Finance tab
// (goals + forecast), so money lives in one place.
//
// Every POST handler below is UNCHANGED from the previous version. The
// only edit is where they redirect to afterwards: they used to send you
// to analyse.php#finance, and now they keep you on Finance.
// ============================================================

const INCOME_CATEGORIES = ['Salary', 'Freelance', 'Investment', 'Gift', 'Other'];
const EXPENSE_CATEGORIES = ['Food', 'Rent', 'Transportation', 'Household', 'Utilities',
    'Entertainment', 'Shopping', 'Health', 'Education', 'Subscriptions', 'Travel', 'Other'];

// ---- Add transaction ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_txn'])) {
    $type = ($_POST['type'] ?? 'expense') === 'income' ? 'income' : 'expense';
    $category = trim($_POST['category'] ?? 'Other');
    $amount = (float)($_POST['amount'] ?? 0);
    $txn_date = $_POST['txn_date'] ?? date('Y-m-d');
    $note = trim($_POST['note'] ?? '');
    if ($amount > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $txn_date)) {
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, category, amount, txn_date, note) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$uid, $type, $category, $amount, $txn_date, $note]);
    }
    header('Location: finance.php?added=1');
    exit;
}

// ---- Delete transaction ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_txn'])) {
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id=? AND user_id=?");
    $stmt->execute([(int)$_POST['txn_id'], $uid]);
    header('Location: finance.php');
    exit;
}

// ---- Add financial goal ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_fin_goal'])) {
    $title = trim($_POST['title'] ?? '');
    $goal_type = $_POST['goal_type'] ?? 'savings';
    if (!isset(GOAL_TYPE_META[$goal_type])) $goal_type = 'savings';
    $target_amount = (float)($_POST['target_amount'] ?? 0);
    $starting_amount = max(0, (float)($_POST['starting_amount'] ?? 0));
    $target_date = $_POST['target_date'] ?? '';
    if ($title !== '' && $target_amount > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
        $stmt = $pdo->prepare("INSERT INTO financial_goals (user_id, goal_type, title, target_amount, starting_amount, start_date, target_date) VALUES (?,?,?,?,?,CURDATE(),?)");
        $stmt->execute([$uid, $goal_type, $title, $target_amount, $starting_amount, $target_date]);
    }
    header('Location: finance.php?goal_added=1');
    exit;
}

// ---- Delete financial goal ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_fin_goal'])) {
    $stmt = $pdo->prepare("DELETE FROM financial_goals WHERE id=? AND user_id=?");
    $stmt->execute([(int)$_POST['goal_id'], $uid]);
    header('Location: finance.php');
    exit;
}

// ---- Add a contribution toward a financial goal ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_contribution'])) {
    $gid = (int)($_POST['goal_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $contributed_at = $_POST['contributed_at'] ?? date('Y-m-d');
    if ($gid > 0 && $amount > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $contributed_at)) {
        // ownership check — only insert if this goal really belongs to the user
        $own = $pdo->prepare("SELECT id FROM financial_goals WHERE id=? AND user_id=?");
        $own->execute([$gid, $uid]);
        if ($own->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO goal_contributions (goal_id, user_id, amount, contributed_at) VALUES (?,?,?,?)");
            $stmt->execute([$gid, $uid, $amount, $contributed_at]);
        }
    }
    header('Location: finance.php?contributed=1');
    exit;
}

// ---- Page data (queries unchanged; only the markup below was rewritten) ----
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$stmt = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) total FROM transactions WHERE user_id=? AND txn_date BETWEEN ? AND ? GROUP BY type");
$stmt->execute([$uid, $month_start, $month_end]);
$totals = ['income' => 0.0, 'expense' => 0.0];
foreach ($stmt->fetchAll() as $r) $totals[$r['type']] = (float)$r['total'];
$net_savings = $totals['income'] - $totals['expense'];
$savings_rate = $totals['income'] > 0 ? (int)round(($net_savings / $totals['income']) * 100) : 0;

$stmt = $pdo->prepare("SELECT category, SUM(amount) total FROM transactions WHERE user_id=? AND type='expense' AND txn_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$stmt->execute([$uid, $month_start, $month_end]);
$cat_breakdown = $stmt->fetchAll();
$cat_max = 0;
foreach ($cat_breakdown as $c) $cat_max = max($cat_max, (float)$c['total']);

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY txn_date DESC, id DESC LIMIT 12");
$stmt->execute([$uid]);
$recent_txns = $stmt->fetchAll();

$fin_goals = financial_goals_with_forecast($pdo, $uid);
$health = financial_health_score($pdo, $uid, $fin_goals);
$next = nar_next($pdo, $uid);

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">Finance</p>
            <h1><?php if ($totals['income'] == 0 && $totals['expense'] == 0): ?>
                Nothing logged this month yet.
            <?php elseif ($net_savings >= 0): ?>
                You've kept <em><?= nar_money($net_savings) ?></em> of what came in.
            <?php else: ?>
                You're <em><?= nar_money(abs($net_savings)) ?></em> down this month.
            <?php endif; ?></h1>
            <p class="hero-sub"><?php if ($totals['income'] == 0 && $totals['expense'] == 0): ?>
                Add what you've earned and spent, and the pattern starts showing up here.
            <?php else: ?>
                <?= nar_money($totals['income']) ?> in and <?= nar_money($totals['expense']) ?> out since the 1st<?= $savings_rate > 0 ? ', keeping ' . $savings_rate . '% of it' : '' ?>.
            <?php endif; ?></p>
        </div>
        <div class="hero-stats">
            <?php if ($health): ?>
            <div>
                <div class="hstat-n"><span data-count="<?= $health['score'] ?>"><?= $health['score'] ?></span><span class="u">/100</span></div>
                <div class="hstat-l">money health</div>
            </div>
            <?php endif; ?>
            <div>
                <div class="hstat-n"><span data-count="<?= $savings_rate ?>" data-post="%"><?= $savings_rate ?>%</span></div>
                <div class="hstat-l">of income kept</div>
            </div>
        </div>
    </div>
</header>

<div class="wrap">

<?php if (isset($_GET['added'])): ?><div class="flash" style="margin-top:34px;">Logged.</div>
<?php elseif (isset($_GET['goal_added'])): ?><div class="flash" style="margin-top:34px;">Goal set. Add to it whenever you can.</div>
<?php elseif (isset($_GET['contributed'])): ?><div class="flash" style="margin-top:34px;">Added to your goal.</div>
<?php endif; ?>

<!-- ---------- Where it goes ---------- -->
<section class="ch enter">
    <div class="ch-head" style="justify-content:space-between; width:100%;">
        <h2>Where it's going</h2>
        <button class="btn btn-go" onclick="document.getElementById('txnSheet').classList.add('show')">Add a transaction</button>
    </div>
    <p class="ch-lead">This month's spending, biggest first. The top one or two are usually where the real room is.</p>

    <?php if (empty($cat_breakdown)): ?>
        <div class="panel" style="text-align:center; padding:44px 24px;">
            <h3 style="font-size:20px;">No spending logged this month.</h3>
            <p style="color:var(--ink-mid); font-size:14.5px; margin:10px auto 22px; max-width:40ch;">
                Add a few transactions and this fills in on its own.
            </p>
            <button class="btn btn-go" onclick="document.getElementById('txnSheet').classList.add('show')">Add a transaction</button>
        </div>
    <?php else: ?>
    <div class="panel">
        <div class="bars">
            <?php foreach (array_slice($cat_breakdown, 0, 8) as $c):
                $w = $cat_max > 0 ? round((float)$c['total'] / $cat_max * 100) : 0; ?>
            <div class="barrow">
                <span class="barrow-l"><?= htmlspecialchars($c['category']) ?></span>
                <span class="barrow-t" style="--w:<?= $w ?>%"><i></i></span>
                <span class="barrow-v"><?= nar_money($c['total']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>

<!-- ---------- What's next ---------- -->
<?php if ($next['money']): $m = $next['money'];
    $mlabels = $m['months'];
    foreach ($m['f_income'] as $i => $_) $mlabels[] = 'Next ' . ($i + 1); ?>
<section class="ch enter">
    <div class="ch-head"><h2>What's likely next</h2></div>
    <p class="ch-lead">Where your money looks to be heading, going by the rhythm of the last few months.</p>

    <article class="viz">
        <div class="viz-top">
            <h3 class="viz-claim"><?= htmlspecialchars($m['claim']) ?></h3>
            <p class="viz-why"><?= htmlspecialchars($m['why']) ?></p>
        </div>
        <div class="viz-body">
            <div class="chart-scroll"><?= nar_chart($mlabels, [
                'Money in'  => ['actual' => $m['income'],  'forecast' => $m['f_income'],  'color' => '#4A9A58'],
                'Money out' => ['actual' => $m['expense'], 'forecast' => $m['f_expense'], 'color' => '#C4645A'],
            ], 640, 220) ?></div>
        </div>
        <div style="padding:0 30px;"><div class="viz-foot">
            <svg class="viz-foot-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 21V9.5M12 9.5C12 6 9.5 3.5 5.5 3.5c0 4 2.5 6 6.5 6Z"/>
            </svg>
            <p class="viz-foot-t"><b>What this means.</b> <?= htmlspecialchars($m['take']) ?></p>
        </div></div>
    </article>

    <p style="margin-top:20px;"><a class="more" href="simulate.php">See what putting a bit more aside would do</a></p>
</section>
<?php endif; ?>

<!-- ---------- Goals ---------- -->
<section class="ch enter">
    <div class="ch-head" style="justify-content:space-between; width:100%;">
        <h2>What you're saving for</h2>
        <button class="btn btn-line" onclick="document.getElementById('goalSheet').classList.add('show')">Add a goal</button>
    </div>
    <p class="ch-lead">Progress comes from what you actually put in, not what you meant to.</p>

    <?php if (empty($fin_goals)): ?>
        <div class="panel" style="text-align:center; padding:44px 24px;">
            <h3 style="font-size:20px;">Nothing set yet.</h3>
            <p style="color:var(--ink-mid); font-size:14.5px; margin:10px auto 22px; max-width:44ch;">
                A trip, a rainy-day fund, a new laptop. Naming it makes it far more likely to happen.
            </p>
            <button class="btn btn-go" onclick="document.getElementById('goalSheet').classList.add('show')">Add a goal</button>
        </div>
    <?php else: ?>
    <div class="goalcards">
        <?php foreach ($fin_goals as $g):
            $pct = $g['target_amount'] > 0 ? min(100, (int)round($g['current_amount'] / $g['target_amount'] * 100)) : 0;
            $f = $g['forecast'];
            $status = $f['status'] ?? '';
            $meta = GOAL_TYPE_META[$g['goal_type']] ?? ['icon' => '🎯', 'label' => 'Savings'];
            $late = in_array($status, ['at_risk', 'behind', 'off_track'], true);
        ?>
        <article class="goalcard">
            <h3><?= $meta['icon'] ?> <?= htmlspecialchars($g['title']) ?></h3>
            <p class="gt">wanted by <?= date('j M Y', strtotime($g['target_date'])) ?></p>
            <div class="gprog" style="--w:<?= $pct ?>%"><i></i></div>
            <div class="gnum">
                <span><b><?= nar_money($g['current_amount']) ?></b> of <?= nar_money($g['target_amount']) ?></span>
                <span><?= $pct ?>%</span>
            </div>
            <div class="gfoot">
                <?php if ($status === 'achieved'): ?>
                    <span class="tag ok">Reached</span>
                <?php elseif (!empty($f['expected_date'])): ?>
                    <span class="tag <?= $late ? 'late' : 'ok' ?>"><?= $late ? 'Behind pace' : 'On pace' ?></span>
                    <span style="font-size:12.5px; color:var(--ink-soft);">at this rate, <?= date('M Y', strtotime($f['expected_date'])) ?></span>
                <?php endif; ?>
                <button class="btn btn-line" style="padding:7px 16px; font-size:13px; margin-left:auto;"
                        onclick="openContribute(<?= $g['id'] ?>, <?= htmlspecialchars(json_encode($g['title']), ENT_QUOTES) ?>)">Add to it</button>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<!-- ---------- Recent ---------- -->
<?php if ($recent_txns): ?>
<section class="ch enter">
    <div class="ch-head"><h2>Recent activity</h2></div>
    <p class="ch-lead">The last dozen things you logged.</p>
    <div class="panel">
        <div class="ledger">
            <?php foreach ($recent_txns as $t): $inc = $t['type'] === 'income'; ?>
            <div class="led">
                <span class="led-dot <?= $inc ? 'inc' : 'exp' ?>"></span>
                <div>
                    <div class="led-c"><?= htmlspecialchars($t['category']) ?></div>
                    <?php if ($t['note']): ?><div class="led-n"><?= htmlspecialchars($t['note']) ?></div><?php endif; ?>
                </div>
                <span class="led-d"><?= date('j M', strtotime($t['txn_date'])) ?></span>
                <span class="led-a <?= $inc ? 'inc' : '' ?>"><?= $inc ? '+' : '' ?><?= nar_money($t['amount']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

</div><!-- /wrap -->

<!-- ---------- Add transaction ---------- -->
<div class="veil" id="txnSheet">
    <div class="sheet">
        <h2>What happened?</h2>
        <p class="note">Money in or money out — both help Sprout see the pattern.</p>
        <form method="POST">
            <div class="field">
                <label for="t-type">In or out</label>
                <select id="t-type" name="type" onchange="swapCats(this.value)">
                    <option value="expense">Money out</option>
                    <option value="income">Money in</option>
                </select>
            </div>
            <div class="field">
                <label for="t-cat">What for</label>
                <select id="t-cat" name="category">
                    <?php foreach (EXPENSE_CATEGORIES as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="row2">
                <div class="field">
                    <label for="t-amt">How much</label>
                    <input id="t-amt" type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
                <div class="field">
                    <label for="t-date">When</label>
                    <input id="t-date" type="date" name="txn_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="field">
                <label for="t-note">Note (optional)</label>
                <input id="t-note" type="text" name="note" placeholder="Anything worth remembering">
            </div>
            <div class="sheet-foot">
                <button type="button" class="btn btn-line" onclick="document.getElementById('txnSheet').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_txn" class="btn btn-go">Log it</button>
            </div>
        </form>
    </div>
</div>

<!-- ---------- Add goal ---------- -->
<div class="veil" id="goalSheet">
    <div class="sheet">
        <h2>Something to save for</h2>
        <p class="note">Give it a name and a date. Both make it far more likely to happen.</p>
        <form method="POST">
            <div class="field">
                <label for="g-title">What is it?</label>
                <input id="g-title" type="text" name="title" placeholder="Rainy-day fund" required>
            </div>
            <div class="field">
                <label for="g-type">What kind</label>
                <select id="g-type" name="goal_type">
                    <?php foreach (GOAL_TYPE_META as $k => $meta): ?>
                    <option value="<?= $k ?>"><?= $meta['icon'] ?> <?= $meta['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row2">
                <div class="field">
                    <label for="g-target">How much do you need</label>
                    <input id="g-target" type="number" name="target_amount" step="0.01" min="1" required>
                </div>
                <div class="field">
                    <label for="g-start">Already put by</label>
                    <input id="g-start" type="number" name="starting_amount" step="0.01" min="0" value="0">
                </div>
            </div>
            <div class="field">
                <label for="g-date">By when</label>
                <input id="g-date" type="date" name="target_date" min="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="sheet-foot">
                <button type="button" class="btn btn-line" onclick="document.getElementById('goalSheet').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_fin_goal" class="btn btn-go">Set the goal</button>
            </div>
        </form>
    </div>
</div>

<!-- ---------- Contribute ---------- -->
<div class="veil" id="contribSheet">
    <div class="sheet">
        <h2>Add to <span id="contribName"></span></h2>
        <p class="note">Every bit counts, and it all shows up on the bar.</p>
        <form method="POST">
            <input type="hidden" name="goal_id" id="contribId">
            <div class="row2">
                <div class="field">
                    <label for="c-amt">How much</label>
                    <input id="c-amt" type="number" name="amount" step="0.01" min="0.01" required>
                </div>
                <div class="field">
                    <label for="c-date">When</label>
                    <input id="c-date" type="date" name="contributed_at" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="sheet-foot">
                <button type="button" class="btn btn-line" onclick="document.getElementById('contribSheet').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_contribution" class="btn btn-go">Add it</button>
            </div>
        </form>
    </div>
</div>

<script>
const INCOME_CATS  = <?= json_encode(INCOME_CATEGORIES) ?>;
const EXPENSE_CATS = <?= json_encode(EXPENSE_CATEGORIES) ?>;

/* The category list follows the in/out choice, so nobody files a salary
   under "Groceries". */
function swapCats(type) {
    const sel = document.getElementById('t-cat');
    const list = type === 'income' ? INCOME_CATS : EXPENSE_CATS;
    sel.innerHTML = list.map(c => '<option value="' + c + '">' + c + '</option>').join('');
}

function openContribute(id, name) {
    document.getElementById('contribId').value = id;
    document.getElementById('contribName').textContent = name;
    document.getElementById('contribSheet').classList.add('show');
}

document.querySelectorAll('.veil').forEach(v => {
    v.addEventListener('click', e => { if (e.target === v) v.classList.remove('show'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.veil.show').forEach(v => v.classList.remove('show'));
});
</script>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
