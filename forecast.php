<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Forecast';
$active = 'forecast';

// ML-backed predictions (written by ml/train_model.py; null until it's been run once)
$finance = get_forecast($pdo, $uid, 'finance');

require_once __DIR__ . '/includes/header.php';

// small helpers local to this page
function method_label($m) { if ($m === 'arima') return 'ARIMA'; return $m ? ucwords(str_replace('_', ' ', $m)) : '—'; }
function money($n) { return '₹' . number_format((float)$n, 0); }
function end_val($arr) { return !empty($arr) ? end($arr) : 0; }
?>

<div class="coach-hero anim-in" style="background: linear-gradient(135deg, var(--lavender), var(--sky)); color:var(--ink);">
    <div class="coach-avatar" style="background: var(--ink); color: var(--lime);">🔮</div>
    <div style="flex:1;">
        <h3 style="color:var(--ink);">Financial Forecast</h3>
        <p style="color:#3A3A45;">Three candidate models — Linear Regression, Moving Average and ARIMA — backtested on your own history; whichever predicted your recent months most accurately is used. No external AI API — every number traces back to <code>ml/forecasting.py</code>. Looking for productivity &amp; habit forecasts? Head to <a href="productivity.php" style="color:var(--ink); text-decoration:underline;">Productivity Analysis</a>.</p>
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
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;">
                <span class="count-up" data-prefix="₹" data-target="<?= $f['income'][0] ?? 0 ?>">₹0</span>
            </div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Projected expense</h4>
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;">
                <span class="count-up" data-prefix="₹" data-target="<?= $f['expense'][0] ?? 0 ?>">₹0</span>
            </div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Projected profit</h4>
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;">
                <span class="count-up" data-prefix="₹" data-target="<?= $f['profit'][0] ?? 0 ?>">₹0</span>
            </div>
        </div>
        <div class="bento-cell anim-in">
            <h4>Profit margin
                <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Projected profit as a percentage of projected revenue next month — how much of every rupee earned is kept, not spent.</span></span>
            </h4>
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;">
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
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px; color:<?= ($has_cash && ($f['cash_flow'][0] ?? 0) >= 0) ? 'var(--ink)' : '#B8447A' ?>">
                <?php if ($has_cash): ?>
                <span class="count-up" data-prefix="₹" data-target="<?= $f['cash_flow'][0] ?? 0 ?>">₹0</span>
                <?php else: ?>—<?php endif; ?>
            </div>
            <div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;">end of next month</div>
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

    <div class="card anim-in" style="margin-bottom:24px;">
        <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:4px;">
            Income vs. expenses — actual &amp; forecast
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">A Linear Regression fits a trend line through your monthly totals; a Moving Average projects your recent average forward. Whichever backtested more accurately draws the dashed line.</span></span>
        </h4>
        <p class="chart-note">Solid = what you actually logged. Dashed = the model's projection — nothing past the last solid dot has happened yet.</p>
        <div class="chart-draw">
        <?php
        $labels = array_merge($h['months'], array_fill(0, count($f['income']), ''));
        $fc_count = count($f['income']);
        for ($i = 0; $i < $fc_count; $i++) $labels[count($h['months']) + $i] = '+' . ($i + 1) . 'mo';
        echo svg_line_chart($labels, [
            'Income' => ['actual' => $h['income'], 'forecast' => $f['income'], 'color' => '#5C8AE6'],
            'Expense' => ['actual' => $h['expense'], 'forecast' => $f['expense'], 'color' => '#EE8AD1'],
        ]);
        ?>
        </div>
        <details class="model-details">
            <summary>Why these numbers? (model performance)</summary>
            <div style="overflow-x:auto;">
            <table class="model-table">
                <tr><th>Model</th><th>Income MAE</th><th>Income RMSE</th><th>Income MAPE</th><th>Expense MAE</th><th>Expense RMSE</th><th>Expense MAPE</th></tr>
                <?php foreach (['linear_trend', 'moving_average', 'arima'] as $m):
                    $is = $finance['model']['income_scores'][$m] ?? null;
                    $es = $finance['model']['expense_scores'][$m] ?? null;
                    $is_income_winner = $finance['model']['income_method'] === $m;
                    $is_expense_winner = $finance['model']['expense_method'] === $m;
                ?>
                <tr>
                    <td><strong><?= method_label($m) ?></strong></td>
                    <td<?= $is_income_winner ? ' style="color:#2F5233;font-weight:800;"' : '' ?>><?= $is ? '₹' . $is['mae'] : '—' ?><?= $is_income_winner ? ' ✓' : '' ?></td>
                    <td><?= $is ? '₹' . $is['rmse'] : '—' ?></td>
                    <td><?= ($is && $is['mape'] !== null) ? $is['mape'] . '%' : '—' ?></td>
                    <td<?= $is_expense_winner ? ' style="color:#2F5233;font-weight:800;"' : '' ?>><?= $es ? '₹' . $es['mae'] : '—' ?><?= $is_expense_winner ? ' ✓' : '' ?></td>
                    <td><?= $es ? '₹' . $es['rmse'] : '—' ?></td>
                    <td><?= ($es && $es['mape'] !== null) ? $es['mape'] . '%' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
            <p style="font-size:11.5px;color:var(--ink-soft);margin-top:10px;">MAE/RMSE/MAPE come from backtesting: predicting the last few real months using only earlier months, then comparing to what actually happened. Lower is better on all three — ✓ marks the model actually used for the forecast above (income and expense are picked independently, since one series can be steadier than the other).</p>
        </details>
    </div>

    <?php if (!empty($finance['model_forecasts_next_month'])): $mf = $finance['model_forecasts_next_month']; ?>
    <div class="card anim-in" style="margin-bottom:24px;">
        <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:4px;">
            Forecast summary — next month, by model
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">What each of the three candidate models predicts for next month, side by side — not just the winner. The KPI cards above use whichever model backtested best for that specific metric.</span></span>
        </h4>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:10px; min-width:520px;">
            <tr style="color:var(--ink-soft); font-weight:700; text-align:left;">
                <th style="padding:4px 10px 8px;">Metric</th>
                <th style="padding:4px 10px 8px;">Linear Regression</th>
                <th style="padding:4px 10px 8px;">Moving Average</th>
                <th style="padding:4px 10px 8px;">ARIMA</th>
            </tr>
            <tr><td style="padding:7px 10px;">Revenue</td><td style="padding:7px 10px;"><?= money($mf['linear_trend']['revenue']) ?></td><td style="padding:7px 10px;"><?= money($mf['moving_average']['revenue']) ?></td><td style="padding:7px 10px;"><?= money($mf['arima']['revenue']) ?></td></tr>
            <tr><td style="padding:7px 10px;">Expense</td><td style="padding:7px 10px;"><?= money($mf['linear_trend']['expense']) ?></td><td style="padding:7px 10px;"><?= money($mf['moving_average']['expense']) ?></td><td style="padding:7px 10px;"><?= money($mf['arima']['expense']) ?></td></tr>
            <tr><td style="padding:7px 10px;">Profit</td><td style="padding:7px 10px;"><?= money($mf['linear_trend']['profit']) ?></td><td style="padding:7px 10px;"><?= money($mf['moving_average']['profit']) ?></td><td style="padding:7px 10px;"><?= money($mf['arima']['profit']) ?></td></tr>
            <tr><td style="padding:7px 10px;">Profit margin</td><td style="padding:7px 10px;"><?= $mf['linear_trend']['profit_margin'] ?>%</td><td style="padding:7px 10px;"><?= $mf['moving_average']['profit_margin'] ?>%</td><td style="padding:7px 10px;"><?= $mf['arima']['profit_margin'] ?>%</td></tr>
            <tr><td style="padding:7px 10px;">Cash flow (cumulative)</td><td style="padding:7px 10px;"><?= money($mf['linear_trend']['cash_flow']) ?></td><td style="padding:7px 10px;"><?= money($mf['moving_average']['cash_flow']) ?></td><td style="padding:7px 10px;"><?= money($mf['arima']['cash_flow']) ?></td></tr>
        </table>
        </div>
        <p style="font-size:11.5px; color:var(--ink-soft); margin-top:10px;">The KPI cards at the top of this page use <strong><?= method_label($finance['model']['income_method']) ?></strong> for revenue and <strong><?= method_label($finance['model']['expense_method']) ?></strong> for expense — whichever backtested more accurately per the table above.</p>
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

    <?php if ($has_cash): ?>
    <div class="card anim-in" style="margin-bottom:24px;">
        <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:4px;">
            Cumulative cash flow — actual &amp; forecast
            <span class="info-dot" tabindex="0" onclick="this.classList.toggle('open')">i<span class="tip">Running total of every month's profit, added together. A rising line means your overall cash position is building up; a falling line means it's draining down.</span></span>
        </h4>
        <p class="chart-note">Solid = your running balance so far. Dashed = projected, based on forecast profit.</p>
        <div class="chart-draw">
        <?php
        $cf_labels = array_merge($h['months'], array_fill(0, count($f['cash_flow']), ''));
        $cf_fc_count = count($f['cash_flow']);
        for ($i = 0; $i < $cf_fc_count; $i++) $cf_labels[count($h['months']) + $i] = '+' . ($i + 1) . 'mo';
        echo svg_line_chart($cf_labels, [
            'Cash flow' => ['actual' => $h['cash_flow'], 'forecast' => $f['cash_flow'], 'color' => '#5C7A17'],
        ]);
        ?>
        </div>
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
<?php endif; ?>

<?php if ($finance && isset($finance['_generated_at'])): ?>
<p style="font-size:11.5px; color:var(--ink-soft); text-align:right; margin-top:14px;">Last trained: <?= date('d M Y, g:i a', strtotime($finance['_generated_at'])) ?></p>
<?php endif; ?>

<script>
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
