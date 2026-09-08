<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Finance';
$active = 'finance';

// Finance — its own domain (a distinct kind of tracking from habits), but
// merged with what used to be a separate Forecast page: the forecast is a
// mode of looking at your finances, not a different destination. "This
// Month" and "Forecast" below are the two tabs.

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

// ============================================================
// "This Month" tab
// ============================================================
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$stmt = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) total FROM transactions WHERE user_id=? AND txn_date BETWEEN ? AND ? GROUP BY type");
$stmt->execute([$uid, $month_start, $month_end]);
$totals = ['income' => 0.0, 'expense' => 0.0];
foreach ($stmt->fetchAll() as $r) $totals[$r['type']] = (float)$r['total'];
$net_savings = $totals['income'] - $totals['expense'];
$savings_rate = $totals['income'] > 0 ? round(($net_savings / $totals['income']) * 100) : 0;

$stmt = $pdo->prepare("SELECT category, SUM(amount) total FROM transactions WHERE user_id=? AND type='expense' AND txn_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$stmt->execute([$uid, $month_start, $month_end]);
$cat_breakdown = $stmt->fetchAll();
$cat_max = 0;
foreach ($cat_breakdown as $c) $cat_max = max($cat_max, (float)$c['total']);

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY txn_date DESC, id DESC LIMIT 15");
$stmt->execute([$uid]);
$recent_txns = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT DATE_FORMAT(txn_date, '%Y-%m')) c FROM transactions WHERE user_id=?");
$stmt->execute([$uid]);
$months_logged = (int)$stmt->fetch()['c'];

$cat_colors = ['#5C8AE6','#EE8AD1','#9391F5','#D7F171','#F7B1E3','#C7D8FF','#FFD7C2','#B9F0D3','#EAA1D8','#8FAE8B','#B8B7FF','#FFB1A1'];
$donut_segments = [];
foreach ($cat_breakdown as $i => $c) {
    $donut_segments[$c['category']] = ['value' => (float)$c['total'], 'color' => $cat_colors[$i % count($cat_colors)]];
}
$exp_donut = svg_donut_chart($donut_segments);

$history6 = monthly_transaction_totals($pdo, $uid, 6);

// ============================================================
// "Forecast" tab — ML-backed predictions (written by ml/train_model.py)
// ============================================================
$finance = get_forecast($pdo, $uid, 'finance');
function method_label($m) { return forecast_method_label($m); }
function money($n) { return '₹' . number_format((float)$n, 0); }
function end_val($arr) { return !empty($arr) ? end($arr) : 0; }

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success">Transaction added 💰</div><?php endif; ?>

<div class="tab-group" id="financeTabs">
<div class="seg-tabs">
    <button class="active" data-tab-target="month">This Month</button>
    <button data-tab-target="forecast">Forecast</button>
</div>

<div class="tab-panel active" data-tab-panel="month">
<div class="section-title">
    <h2>This month</h2>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('addTxnModal').classList.add('show')">+ Add transaction</button>
</div>

<div class="bento-grid">
    <div class="bento-cell anim-in">
        <h4>Income</h4>
        <div class="stat-value"><span class="count-up" data-prefix="₹" data-target="<?= $totals['income'] ?>">₹0</span></div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Expenses</h4>
        <div class="stat-value"><span class="count-up" data-prefix="₹" data-target="<?= $totals['expense'] ?>">₹0</span></div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Net savings</h4>
        <div class="stat-value <?= $net_savings >= 0 ? '' : 'text-attention' ?>"><span class="count-up" data-prefix="₹" data-target="<?= $net_savings ?>">₹0</span></div>
    </div>
    <div class="bento-cell anim-in">
        <h4>Savings rate</h4>
        <div class="stat-value"><span class="count-up" data-target="<?= $savings_rate ?>" data-suffix="%">0%</span></div>
    </div>
</div>

<div class="bento-grid">
    <div class="bento-cell span-2">
        <h4>Spending by category — this month</h4>
        <?php if (empty($cat_breakdown)): ?>
            <div class="empty-state"><div class="em-ico">💸</div><p>No expenses logged yet this month.</p></div>
        <?php else: foreach ($cat_breakdown as $c): $pct = $cat_max > 0 ? round(($c['total'] / $cat_max) * 100) : 0; ?>
        <div class="tracker-row">
            <span><?= htmlspecialchars($c['category']) ?></span>
            <div class="tracker-bar"><span class="grow-in" style="width:<?= $pct ?>%"></span></div>
            <strong>₹<?= number_format($c['total'], 0) ?></strong>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <?php
    if (empty($donut_segments)) {
        $donut_content = null;
    } else {
        ob_start(); ?>
        <div class="donut-wrap">
            <div class="donut-figure">
                <?= $exp_donut['svg'] ?>
                <div class="donut-center-label">
                    <div class="dc-num">₹<?= number_format($exp_donut['total'], 0) ?></div>
                    <div class="dc-label">this month</div>
                </div>
            </div>
            <div class="donut-legend">
                <?php foreach ($exp_donut['legend'] as $name => $seg): ?>
                <div class="dl-row">
                    <span class="dl-dot" style="background:<?= $seg['color'] ?>;"></span>
                    <span><?= htmlspecialchars($name) ?></span>
                    <span class="dl-pct"><?= $seg['pct'] ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php $donut_content = ob_get_clean();
    }
    ?>
    <?= chart_card([
        'title' => 'Category breakdown',
        'info' => 'The same category totals as the bars on the left, drawn as a donut so you can see relative share at a glance.',
        'span' => 2,
        'content' => $donut_content,
        'empty_message' => 'No expenses logged yet this month.',
    ]) ?>
</div>

<div class="bento-grid">
<?= chart_card([
    'title' => 'Income vs. expenses — last ' . (count($history6['months']) ?: 6) . ' months',
    'info' => 'Straight from your own logged transactions — no forecasting or benchmark blending here, just what actually happened month by month.',
    'span' => 4,
    'content' => empty($history6['months']) ? null : svg_line_chart($history6['months'], [
        'Income' => ['actual' => $history6['income'], 'forecast' => [], 'color' => '#5C8AE6'],
        'Expense' => ['actual' => $history6['expense'], 'forecast' => [], 'color' => '#EE8AD1'],
    ], 1100, 220),
    'chart_width' => 1100,
    'empty_message' => 'Log a few transactions across different months to see this chart fill in.',
]) ?>
</div>

<div class="section-title"><h2>Recent transactions</h2></div>
<?php if (empty($recent_txns)): ?>
    <div class="card empty-state">
        <div class="em-ico">💰</div>
        <p>No transactions yet. Add your first income or expense above.</p>
    </div>
<?php else: ?>
<div class="card" style="padding:8px 26px;">
    <?php foreach ($recent_txns as $t): ?>
    <div class="tracker-row">
        <span>
            <strong><?= htmlspecialchars($t['category']) ?></strong>
            <span style="color:var(--ink-soft); font-weight:500;"> · <?= date('d M', strtotime($t['txn_date'])) ?><?= $t['note'] ? ' · ' . htmlspecialchars($t['note']) : '' ?></span>
        </span>
        <strong style="margin-left:auto; margin-right:16px;" class="<?= $t['type'] === 'income' ? 'text-good' : 'text-attention' ?>">
            <?= $t['type'] === 'income' ? '+' : '−' ?>₹<?= number_format($t['amount'], 0) ?>
        </strong>
        <form method="POST" onsubmit="return confirm('Delete this transaction?');">
            <input type="hidden" name="txn_id" value="<?= $t['id'] ?>">
            <button type="submit" name="delete_txn" class="icon-btn" title="Delete" style="width:30px;height:30px;">🗑</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<div class="tab-panel" data-tab-panel="forecast">
<div class="coach-hero anim-in" style="background: linear-gradient(135deg, var(--lavender), var(--sky)); color:var(--ink);">
    <div class="coach-avatar" style="background: var(--ink); color: var(--lime);">🔮</div>
    <div style="flex:1;">
        <h3 style="color:var(--ink);">Financial Forecast</h3>
        <p style="color:#3A3A45;">Two candidate models — Linear Regression and ARIMA — backtested on your own history; whichever predicted your recent months most accurately is used, and its own backtest error draws the shaded 95% confidence band on the chart below. No external AI API — every number traces back to <code>ml/forecasting.py</code>. Looking for productivity &amp; habit forecasts? Head to <a href="insights.php#forecast" style="color:var(--ink); text-decoration:underline;">Insights</a>.</p>
    </div>
    <button class="btn btn-ghost btn-sm" id="refreshBtn" onclick="refreshForecast()">↻ Retrain now</button>
</div>
<div id="refreshMsg" style="display:none; margin-bottom:18px;"></div>

<?php if (!$finance || $finance['status'] === 'no_data'): ?>
    <div class="card empty-state">
        <div class="em-ico">🔮</div>
        <p><?= $finance ? htmlspecialchars($finance['message']) : 'No forecast yet. Run <code>python ml/train_model.py</code> once from the project folder, then refresh this page.' ?></p>
    </div>

<?php elseif ($finance['status'] === 'benchmark_preview'): ?>
    <div class="benchmark-banner anim-in">
        <h4>🔍 Preview using the benchmark dataset</h4>
        <p><?= htmlspecialchars($finance['message']) ?></p>
    </div>
    <div class="card anim-in">
        <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:14px;">
            Typical spending breakdown
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">From ml/data/finance_benchmark.xlsx — a benchmark dataset, not your own spending yet. It's replaced by your real category split the moment you log a transaction.</span></span>
        </h4>
        <?php $bshares = $finance['benchmark_category_shares']; $bmax = $bshares ? max($bshares) : 1; ?>
        <?php foreach ($bshares as $cat => $pct): ?>
        <div class="tracker-row">
            <span><?= htmlspecialchars($cat) ?></span>
            <div class="tracker-bar"><span class="grow-in" style="width:<?= $bmax > 0 ? round(($pct / $bmax) * 100) : 0 ?>%; background: var(--lavender-deep);"></span></div>
            <strong><?= $pct ?>%</strong>
        </div>
        <?php endforeach; ?>
    </div>

<?php elseif ($finance['status'] !== 'ok'): ?>
    <div class="card empty-state">
        <div class="em-ico">💰</div>
        <p><?= htmlspecialchars($finance['message']) ?></p>
    </div>

<?php else:
    $h = $finance['history'];
    $f = $finance['forecast'];
    $has_margin = isset($h['profit_margin']) && isset($f['profit_margin']);
    $has_cash = isset($h['cash_flow']) && isset($f['cash_flow']);
?>
    <div class="bento-grid">
        <div class="bento-cell anim-in">
            <h4>Projected revenue
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Next month's income, forecast from your logged transactions.</span></span>
            </h4>
            <div class="stat-value">
                <span class="count-up" data-prefix="₹" data-target="<?= $f['income'][0] ?? 0 ?>">₹0</span>
            </div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Projected expense</h4>
            <div class="stat-value">
                <span class="count-up" data-prefix="₹" data-target="<?= $f['expense'][0] ?? 0 ?>">₹0</span>
            </div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Projected profit</h4>
            <div class="stat-value">
                <span class="count-up" data-prefix="₹" data-target="<?= $f['profit'][0] ?? 0 ?>">₹0</span>
            </div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Profit margin
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Projected profit as a percentage of projected revenue next month — how much of every rupee earned is kept, not spent.</span></span>
            </h4>
            <div class="stat-value">
                <?php if ($has_margin): ?>
                <span class="count-up" data-target="<?= $f['profit_margin'][0] ?? 0 ?>" data-suffix="%">0%</span>
                <?php else: ?>—<?php endif; ?>
            </div>
        </div>
    </div>

    <div class="bento-grid">
        <div class="bento-cell anim-in">
            <h4>Cash flow (projected, cumulative)
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Your running cash position — the cumulative total of every month's profit added together. Unlike "profit" (a per-month figure), cash flow shows whether your overall balance is building up or draining down over time.</span></span>
            </h4>
            <div class="stat-value <?= ($has_cash && ($f['cash_flow'][0] ?? 0) >= 0) ? '' : 'text-attention' ?>">
                <?php if ($has_cash): ?>
                <span class="count-up" data-prefix="₹" data-target="<?= $f['cash_flow'][0] ?? 0 ?>">₹0</span>
                <?php else: ?>—<?php endif; ?>
            </div>
            <div class="caption" style="margin-top:4px;">end of next month</div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Model used
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Chosen automatically: the model that predicted your last few real months more accurately during backtesting wins. See "Why these numbers?" below the chart.</span></span>
            </h4>
            <div style="font-size:14px; font-weight:700; margin-top:6px;"><?= method_label($finance['model']['expense_method']) ?></div>
            <div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;">MAE ₹<?= $finance['model']['expense_mae'] ?? '—' ?> · RMSE ₹<?= $finance['model']['expense_rmse'] ?? '—' ?></div>
        </div>
        <div class="bento-cell span-2 anim-in" style="justify-content:center;">
            <h4 style="text-transform:uppercase; font-size:12px; color:var(--ink-soft); letter-spacing:.04em;">Forecast summary</h4>
            <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:6px;">
                <tr style="color:var(--ink-soft); font-weight:700; text-align:left;">
                    <th style="padding:4px 6px 8px;">Metric</th>
                    <th style="padding:4px 6px 8px;">This month</th>
                    <th style="padding:4px 6px 8px;">Next month (forecast)</th>
                </tr>
                <tr><td style="padding:6px;">Revenue</td><td style="padding:6px;"><?= money(end_val($h['income'])) ?></td><td style="padding:6px; font-weight:700;"><?= money($f['income'][0] ?? 0) ?></td></tr>
                <tr><td style="padding:6px;">Expense</td><td style="padding:6px;"><?= money(end_val($h['expense'])) ?></td><td style="padding:6px; font-weight:700;"><?= money($f['expense'][0] ?? 0) ?></td></tr>
                <tr><td style="padding:6px;">Profit</td><td style="padding:6px;"><?= money(end_val($h['profit'])) ?></td><td style="padding:6px; font-weight:700;"><?= money($f['profit'][0] ?? 0) ?></td></tr>
                <?php if ($has_margin): ?>
                <tr><td style="padding:6px;">Profit margin</td><td style="padding:6px;"><?= end_val($h['profit_margin']) ?>%</td><td style="padding:6px; font-weight:700;"><?= $f['profit_margin'][0] ?? 0 ?>%</td></tr>
                <?php endif; ?>
                <?php if ($has_cash): ?>
                <tr><td style="padding:6px;">Cash flow (cumulative)</td><td style="padding:6px;"><?= money(end_val($h['cash_flow'])) ?></td><td style="padding:6px; font-weight:700;"><?= money($f['cash_flow'][0] ?? 0) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <?php
    $labels = array_merge($h['months'], array_fill(0, count($f['income']), ''));
    $fc_count = count($f['income']);
    for ($i = 0; $i < $fc_count; $i++) $labels[count($h['months']) + $i] = '+' . ($i + 1) . 'mo';
    $income_expense_chart = svg_line_chart($labels, [
        'Income' => ['actual' => $h['income'], 'forecast' => $f['income'], 'color' => '#5C8AE6', 'ci_lower' => $f['income_lower'] ?? [], 'ci_upper' => $f['income_upper'] ?? []],
        'Expense' => ['actual' => $h['expense'], 'forecast' => $f['expense'], 'color' => '#EE8AD1', 'ci_lower' => $f['expense_lower'] ?? [], 'ci_upper' => $f['expense_upper'] ?? []],
    ]);
    ob_start(); ?>
    <div class="chart-draw"><?= $income_expense_chart ?></div>
    <details class="model-details">
        <summary>Why these numbers? (model performance)</summary>
        <div style="overflow-x:auto;">
        <table class="model-table">
            <tr><th>Model</th><th>Income MAE</th><th>Income RMSE</th><th>Income Accuracy</th><th>Expense MAE</th><th>Expense RMSE</th><th>Expense Accuracy</th></tr>
            <?php foreach (['linear_trend', 'arima'] as $m):
                $is = $finance['model']['income_scores'][$m] ?? null;
                $es = $finance['model']['expense_scores'][$m] ?? null;
                $is_income_winner = $finance['model']['income_method'] === $m;
                $is_expense_winner = $finance['model']['expense_method'] === $m;
            ?>
            <tr>
                <td><strong><?= method_label($m) ?></strong></td>
                <td<?= $is_income_winner ? ' class="text-good" style="font-weight:800;"' : '' ?>><?= $is ? '₹' . $is['mae'] : '—' ?><?= $is_income_winner ? ' ✓' : '' ?></td>
                <td><?= $is ? '₹' . $is['rmse'] : '—' ?></td>
                <td><?= ($is && $is['accuracy'] !== null) ? $is['accuracy'] . '%' : '—' ?></td>
                <td<?= $is_expense_winner ? ' class="text-good" style="font-weight:800;"' : '' ?>><?= $es ? '₹' . $es['mae'] : '—' ?><?= $is_expense_winner ? ' ✓' : '' ?></td>
                <td><?= $es ? '₹' . $es['rmse'] : '—' ?></td>
                <td><?= ($es && $es['accuracy'] !== null) ? $es['accuracy'] . '%' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <p style="font-size:11.5px;color:var(--ink-soft);margin-top:10px;">MAE/RMSE/Accuracy come from backtesting: predicting the last few real months using only earlier months, then comparing to what actually happened. Accuracy = 100% − MAPE (higher is better; MAE/RMSE lower is better) — ✓ marks the model actually used for the forecast above (income and expense are picked independently, since one series can be steadier than the other).</p>
    </details>
    <?php $income_expense_content = ob_get_clean(); ?>
    <div class="bento-grid">
    <?= chart_card([
        'title' => 'Income vs. expenses — actual &amp; forecast',
        'info' => 'Backtested across two candidate models (Linear Regression, ARIMA). Whichever predicted recent months more accurately draws the dashed line.',
        'span' => 4,
        'note' => 'Solid = what you actually logged. Dashed = the model\'s projection. The shaded band is a 95% confidence interval built from the winning model\'s own backtest error — it widens the further out the forecast reaches, since next month is always a safer bet than three months from now.',
        'content' => $income_expense_content,
        'chart_width' => 640,
    ]) ?>
    </div>

    <?php if (!empty($finance['model_forecasts_next_month'])): $mf = $finance['model_forecasts_next_month']; ?>
    <div class="card anim-in" style="margin-bottom:24px;">
        <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:4px;">
            Forecast summary — next month, by model
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">What each of the two candidate models predicts for next month, side by side — not just the winner. The KPI cards above use whichever model backtested best for that specific metric.</span></span>
        </h4>
        <?php if (isset($finance['model']['finance_accuracy']) && $finance['model']['finance_accuracy'] !== null): ?>
        <p style="font-size:13px; margin:2px 0 8px;">Overall finance forecast accuracy: <strong><?= $finance['model']['finance_accuracy'] ?>%</strong> <span style="color:var(--ink-soft); font-size:11.5px;">(average of the winning income and expense models' own backtest accuracy)</span></p>
        <?php endif; ?>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:10px; min-width:400px;">
            <tr style="color:var(--ink-soft); font-weight:700; text-align:left;">
                <th style="padding:4px 10px 8px;">Metric</th>
                <th style="padding:4px 10px 8px;">Linear Regression</th>
                <th style="padding:4px 10px 8px;">ARIMA</th>
            </tr>
            <tr><td style="padding:7px 10px;">Revenue</td><td style="padding:7px 10px;"><?= money($mf['linear_trend']['revenue']) ?></td><td style="padding:7px 10px;"><?= money($mf['arima']['revenue']) ?></td></tr>
            <tr><td style="padding:7px 10px;">Expense</td><td style="padding:7px 10px;"><?= money($mf['linear_trend']['expense']) ?></td><td style="padding:7px 10px;"><?= money($mf['arima']['expense']) ?></td></tr>
            <tr><td style="padding:7px 10px;">Profit</td><td style="padding:7px 10px;"><?= money($mf['linear_trend']['profit']) ?></td><td style="padding:7px 10px;"><?= money($mf['arima']['profit']) ?></td></tr>
            <tr><td style="padding:7px 10px;">Profit margin</td><td style="padding:7px 10px;"><?= $mf['linear_trend']['profit_margin'] ?>%</td><td style="padding:7px 10px;"><?= $mf['arima']['profit_margin'] ?>%</td></tr>
            <tr><td style="padding:7px 10px;">Cash flow (cumulative)</td><td style="padding:7px 10px;"><?= money($mf['linear_trend']['cash_flow']) ?></td><td style="padding:7px 10px;"><?= money($mf['arima']['cash_flow']) ?></td></tr>
        </table>
        </div>
        <p style="font-size:11.5px; color:var(--ink-soft); margin-top:10px;">The KPI cards at the top of this tab use <strong><?= method_label($finance['model']['income_method']) ?></strong> for revenue and <strong><?= method_label($finance['model']['expense_method']) ?></strong> for expense — whichever backtested more accurately per the table above.</p>
    </div>
    <?php endif; ?>

    <div class="card anim-in" style="margin-bottom:24px; background:var(--offwhite); border:1.5px dashed var(--lightgray);">
        <h4 style="text-transform:uppercase; font-size:12px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:8px;">🔗 How your data connects to the benchmark dataset</h4>
        <p style="font-size:13px; font-weight:500; color:var(--ink-soft); line-height:1.6;">
            You've logged <strong><?= $finance['months_of_history_used'] ?? $finance['months_of_history'] ?> month<?= ($finance['months_of_history_used'] ?? $finance['months_of_history']) == 1 ? '' : 's' ?></strong> of your own transactions. The <strong>"Projected category spend"</strong> chart below blends your own category split with the one learned from the 2,378-row benchmark dataset (<code>ml/data/finance_benchmark.xlsx</code>) — weighted
            <strong><?= $finance['user_data_weight_pct'] ?? 100 ?>% your data</strong> and
            <strong><?= 100 - ($finance['user_data_weight_pct'] ?? 100) ?>% benchmark</strong> right now (it reaches 100% your own data after 6 months of history).
            Example: if you've logged mostly <em>Food</em> and <em>Rent</em> so far, but the benchmark also shows typical <em>Transportation</em> and <em>Entertainment</em> spending, those categories still show up in your forecast at a smaller, benchmark-informed share — until you log your own spending in them too.
            The <strong>Income vs. Expense forecast</strong> above this, on the other hand, uses <em>only</em> your own numbers — the benchmark dataset never influences your actual revenue or expense projections, just the category breakdown.
        </p>
    </div>

    <?php if ($has_cash):
        $cf_labels = array_merge($h['months'], array_fill(0, count($f['cash_flow']), ''));
        $cf_fc_count = count($f['cash_flow']);
        for ($i = 0; $i < $cf_fc_count; $i++) $cf_labels[count($h['months']) + $i] = '+' . ($i + 1) . 'mo';
        $cash_flow_content = '<div class="chart-draw">' . svg_line_chart($cf_labels, [
            'Cash flow' => ['actual' => $h['cash_flow'], 'forecast' => $f['cash_flow'], 'color' => '#5C7A17', 'ci_lower' => $f['cash_flow_lower'] ?? [], 'ci_upper' => $f['cash_flow_upper'] ?? []],
        ]) . '</div>';
    ?>
    <div class="bento-grid">
    <?= chart_card([
        'title' => 'Cumulative cash flow — actual &amp; forecast',
        'info' => 'Running total of every month\'s profit, added together. A rising line means your overall cash position is building up; a falling line means it\'s draining down.',
        'span' => 4,
        'note' => 'Solid = your running balance so far. Dashed = projected, based on forecast profit.',
        'content' => $cash_flow_content,
        'chart_width' => 640,
    ]) ?>
    </div>
    <?php endif; ?>

    <div class="bento-grid">
        <div class="bento-cell span-2 anim-in">
            <h4>Projected category spend — next month
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Next month's total expense split by category, using a blend of your own spending habits and the benchmark dataset's typical split — leaning more on your own data every month you log.</span></span>
            </h4>
            <?php $cats = $finance['category_forecast_next_month']; $cmax = $cats ? max($cats) : 1; ?>
            <?php foreach ($cats as $cat => $amt): $pct = $cmax > 0 ? round(($amt / $cmax) * 100) : 0; ?>
            <div class="tracker-row">
                <span><?= htmlspecialchars($cat) ?></span>
                <div class="tracker-bar"><span class="grow-in" style="width:<?= $pct ?>%; background: var(--pink-deep);"></span></div>
                <strong>₹<?= number_format($amt, 0) ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="bento-cell span-2 anim-in">
            <h4>Top insights</h4>
            <?php foreach ($finance['insights'] as $ins): ?>
            <div class="insight-card"><div class="ins-ico">📈</div><p><?= htmlspecialchars($ins) ?></p></div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    $share_axes = []; $share_user = []; $share_bench = [];
    if (!empty($finance['used_kaggle_benchmark']) && !empty($finance['category_shares_benchmark_pct'])) {
        $user_shares = $finance['category_shares_user_pct'] ?? [];
        $bench_shares = $finance['category_shares_benchmark_pct'];
        $top_cats = array_slice(array_keys($bench_shares), 0, 6);
        foreach ($top_cats as $cat) {
            $share_axes[] = $cat;
            $share_user[] = $user_shares[$cat] ?? 0;
            $share_bench[] = $bench_shares[$cat];
        }
    }
    ?>
    <?php if (count($share_axes) >= 3):
        $spend_shape_content = svg_radar_chart($share_axes, [
            'Benchmark' => ['values' => $share_bench, 'color' => '#C7D8FF'],
            'Your spending' => ['values' => $share_user, 'color' => '#5C8AE6'],
        ], max(30, max(array_merge($share_user, $share_bench)) * 1.1));
    ?>
    <div class="bento-grid">
    <?= chart_card([
        'title' => 'Your spending shape vs. the benchmark dataset',
        'info' => 'Your own category split (% of expenses) plotted against the benchmark dataset\'s typical split, for the benchmark\'s top categories — this is the raw comparison behind the blended "Projected category spend" chart above.',
        'span' => 4,
        'content' => $spend_shape_content,
    ]) ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($finance && isset($finance['_generated_at'])): ?>
<p style="font-size:11.5px; color:var(--ink-soft); text-align:right; margin-top:14px;">Last trained: <?= date('d M Y, g:i a', strtotime($finance['_generated_at'])) ?></p>
<?php endif; ?>
</div>
</div>

<!-- Add Transaction Modal -->
<div class="modal-overlay" id="addTxnModal">
    <div class="modal-box">
        <h3>Add transaction</h3>
        <form method="POST">
            <div class="field">
                <label>Type</label>
                <select name="type" id="txnType" onchange="toggleTxnCategories()">
                    <option value="expense">Expense</option>
                    <option value="income">Income</option>
                </select>
            </div>
            <div class="field">
                <label>Category</label>
                <select name="category" id="expenseCats">
                    <?php foreach (EXPENSE_CATEGORIES as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
                </select>
                <select name="category" id="incomeCats" style="display:none;">
                    <?php foreach (INCOME_CATEGORIES as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="field">
                    <label>Amount (₹)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required>
                </div>
                <div class="field">
                    <label>Date</label>
                    <input type="date" name="txn_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="field">
                <label>Note (optional)</label>
                <input type="text" name="note" placeholder="Any details">
            </div>
            <div class="modal-close-row">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addTxnModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_txn" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleTxnCategories() {
    const isIncome = document.getElementById('txnType').value === 'income';
    document.getElementById('incomeCats').style.display = isIncome ? '' : 'none';
    document.getElementById('incomeCats').name = isIncome ? 'category' : '';
    document.getElementById('expenseCats').style.display = isIncome ? 'none' : '';
    document.getElementById('expenseCats').name = isIncome ? '' : 'category';
}

async function refreshForecast() {
    const btn = document.getElementById('refreshBtn');
    const msg = document.getElementById('refreshMsg');
    btn.disabled = true; btn.textContent = 'Retraining...';
    msg.style.display = 'block';
    msg.innerHTML = '<div class="alert" style="background:var(--lightgray);">Running the training script — this can take a few seconds...</div>';
    try {
        const res = await fetch('run_forecast.php', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            msg.innerHTML = '<div class="alert alert-success">Forecast refreshed! Reloading...</div>';
            setTimeout(() => location.reload(), 1200);
        } else {
            msg.innerHTML = '<div class="alert alert-error">Could not run it automatically (' + (data.reason || 'unknown reason') +
                '). Open a terminal in the project folder and run: <code>python ml/train_model.py</code></div>';
            btn.disabled = false; btn.textContent = '↻ Retrain now';
        }
    } catch (e) {
        msg.innerHTML = '<div class="alert alert-error">Could not reach the server. Run <code>python ml/train_model.py</code> manually instead.</div>';
        btn.disabled = false; btn.textContent = '↻ Retrain now';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
