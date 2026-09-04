<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Forecast';
$active = 'forecast';

$finance = get_forecast($pdo, $uid, 'finance');
$habit = get_forecast($pdo, $uid, 'habit');

require_once __DIR__ . '/includes/header.php';
?>

<div class="coach-hero" style="background: linear-gradient(135deg, var(--lavender), var(--sky)); color:var(--ink);">
    <div class="coach-avatar" style="background: var(--ink); color: var(--lime);">🔮</div>
    <div style="flex:1;">
        <h3 style="color:var(--ink);">Forecast</h3>
        <p style="color:#3A3A45;">A linear-regression / moving-average model trained on your own data<?= ($finance['used_kaggle_benchmark'] ?? false) ? ' + a benchmark personal-finance dataset (see ml/data/README_DATASET.md)' : '' ?>. Nothing here calls an external AI API — every number traces back to <code>ml/forecasting.py</code>.</p>
    </div>
    <button class="btn btn-ghost btn-sm" id="refreshBtn" onclick="refreshForecast()">↻ Retrain now</button>
</div>
<div id="refreshMsg" style="display:none; margin-bottom:18px;"></div>

<div class="section-title"><h2>💰 Finance forecast</h2></div>

<?php if (!$finance): ?>
    <div class="card empty-state">
        <div class="em-ico">🔮</div>
        <p>No forecast yet. Run <code>python ml/train_model.py</code> once from the project folder, then refresh this page.</p>
    </div>
<?php elseif ($finance['status'] !== 'ok'): ?>
    <div class="card empty-state">
        <div class="em-ico">💰</div>
        <p><?= htmlspecialchars($finance['message']) ?></p>
    </div>
<?php else: ?>
    <div class="bento-grid">
        <div class="bento-cell">
            <h4>Next month income</h4>
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;">₹<?= number_format($finance['forecast']['income'][0] ?? 0, 0) ?></div>
        </div>
        <div class="bento-cell">
            <h4>Next month expense</h4>
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;">₹<?= number_format($finance['forecast']['expense'][0] ?? 0, 0) ?></div>
        </div>
        <div class="bento-cell">
            <h4>Projected savings</h4>
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;">₹<?= number_format($finance['forecast']['profit'][0] ?? 0, 0) ?></div>
        </div>
        <div class="bento-cell">
            <h4>Model used</h4>
            <div style="font-size:14px; font-weight:700; margin-top:6px; text-transform:capitalize;"><?= str_replace('_', ' ', $finance['model']['expense_method']) ?></div>
            <div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;">MAE ₹<?= $finance['model']['expense_mae'] ?? '—' ?> · RMSE ₹<?= $finance['model']['expense_rmse'] ?? '—' ?></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:24px;">
        <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:14px;">Income vs. expenses — actual & forecast</h4>
        <?php
        $labels = array_merge($finance['history']['months'], array_fill(0, count($finance['forecast']['income']), ''));
        // replace the trailing blank forecast labels with "+1mo" style markers
        $fc_count = count($finance['forecast']['income']);
        for ($i = 0; $i < $fc_count; $i++) $labels[count($finance['history']['months']) + $i] = '+' . ($i + 1) . 'mo';
        echo svg_line_chart($labels, [
            'Income' => ['actual' => $finance['history']['income'], 'forecast' => $finance['forecast']['income'], 'color' => '#5C8AE6'],
            'Expense' => ['actual' => $finance['history']['expense'], 'forecast' => $finance['forecast']['expense'], 'color' => '#EE8AD1'],
        ]);
        ?>
    </div>

    <div class="bento-grid">
        <div class="bento-cell span-2">
            <h4>Projected category spend — next month</h4>
            <?php $cats = $finance['category_forecast_next_month']; $cmax = $cats ? max($cats) : 1; ?>
            <?php foreach ($cats as $cat => $amt): $pct = $cmax > 0 ? round(($amt / $cmax) * 100) : 0; ?>
            <div class="tracker-row">
                <span><?= htmlspecialchars($cat) ?></span>
                <div class="tracker-bar"><span style="width:<?= $pct ?>%; background: var(--pink-deep);"></span></div>
                <strong>₹<?= number_format($amt, 0) ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="bento-cell span-2">
            <h4>Insights</h4>
            <?php foreach ($finance['insights'] as $ins): ?>
            <div class="insight-card"><div class="ins-ico">📈</div><p><?= htmlspecialchars($ins) ?></p></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="section-title"><h2>🌱 Habit & goal forecast</h2></div>

<?php if (!$habit): ?>
    <div class="card empty-state">
        <div class="em-ico">🔮</div>
        <p>No forecast yet. Run <code>python ml/train_model.py</code> once from the project folder, then refresh this page.</p>
    </div>
<?php elseif ($habit['status'] !== 'ok'): ?>
    <div class="card empty-state">
        <div class="em-ico">🌱</div>
        <p><?= htmlspecialchars($habit['message']) ?></p>
    </div>
<?php else: ?>
    <div class="bento-grid">
        <div class="bento-cell">
            <h4>Next week completion</h4>
            <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:26px;"><?= round($habit['forecast']['completion_pct'][0] ?? 0) ?>%</div>
        </div>
        <div class="bento-cell">
            <h4>Model used</h4>
            <div style="font-size:14px; font-weight:700; margin-top:6px; text-transform:capitalize;"><?= str_replace('_', ' ', $habit['model']['method']) ?></div>
            <div style="font-size:11.5px; color:var(--ink-soft); margin-top:4px;">MAE <?= $habit['model']['mae'] ?? '—' ?> pts · RMSE <?= $habit['model']['rmse'] ?? '—' ?> pts</div>
        </div>
        <div class="bento-cell span-2">
            <h4>Insights</h4>
            <?php foreach ($habit['insights'] as $ins): ?>
            <p style="font-size:13.5px; font-weight:500; margin-bottom:6px;">💡 <?= htmlspecialchars($ins) ?></p>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card" style="margin-bottom:24px;">
        <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:14px;">Weekly completion % — actual & forecast</h4>
        <?php
        $wlabels = [];
        $n_actual_weeks = count($habit['history']['weeks']);
        for ($i = 0; $i < $n_actual_weeks; $i++) $wlabels[] = 'Wk ' . ($i + 1);
        $n_fc_weeks = count($habit['forecast']['completion_pct']);
        for ($i = 0; $i < $n_fc_weeks; $i++) $wlabels[] = '+' . ($i + 1);
        echo svg_line_chart($wlabels, [
            'Completion %' => ['actual' => $habit['history']['completion_pct'], 'forecast' => $habit['forecast']['completion_pct'], 'color' => '#9391F5'],
        ]);
        ?>
    </div>
<?php endif; ?>

<?php if (($finance && isset($finance['_generated_at'])) || ($habit && isset($habit['_generated_at']))): ?>
<p style="font-size:11.5px; color:var(--ink-soft); text-align:right;">Last trained: <?= date('d M Y, g:i a', strtotime($finance['_generated_at'] ?? $habit['_generated_at'])) ?></p>
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
