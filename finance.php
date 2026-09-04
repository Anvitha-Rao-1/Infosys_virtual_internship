<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Finance';
$active = 'finance';

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

// ---- This month's summary ----
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$stmt = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) total FROM transactions WHERE user_id=? AND txn_date BETWEEN ? AND ? GROUP BY type");
$stmt->execute([$uid, $month_start, $month_end]);
$totals = ['income' => 0.0, 'expense' => 0.0];
foreach ($stmt->fetchAll() as $r) $totals[$r['type']] = (float)$r['total'];
$net_savings = $totals['income'] - $totals['expense'];
$savings_rate = $totals['income'] > 0 ? round(($net_savings / $totals['income']) * 100) : 0;

// ---- Category breakdown (this month, expenses) ----
$stmt = $pdo->prepare("SELECT category, SUM(amount) total FROM transactions WHERE user_id=? AND type='expense' AND txn_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$stmt->execute([$uid, $month_start, $month_end]);
$cat_breakdown = $stmt->fetchAll();
$cat_max = 0;
foreach ($cat_breakdown as $c) $cat_max = max($cat_max, (float)$c['total']);

// ---- Recent transactions ----
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY txn_date DESC, id DESC LIMIT 15");
$stmt->execute([$uid]);
$recent_txns = $stmt->fetchAll();

// ---- How many months of history (used to explain the forecast blend on the CTA card) ----
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT DATE_FORMAT(txn_date, '%Y-%m')) c FROM transactions WHERE user_id=?");
$stmt->execute([$uid]);
$months_logged = (int)$stmt->fetch()['c'];

require_once __DIR__ . '/includes/header.php';
?>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success">Transaction added 💰</div><?php endif; ?>

<div class="section-title">
    <h2>This month</h2>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('addTxnModal').classList.add('show')">+ Add transaction</button>
</div>

<div class="bento-grid">
    <div class="bento-cell">
        <h4>Income</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:30px;">₹<?= number_format($totals['income'], 0) ?></div>
    </div>
    <div class="bento-cell">
        <h4>Expenses</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:30px;">₹<?= number_format($totals['expense'], 0) ?></div>
    </div>
    <div class="bento-cell">
        <h4>Net savings</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:30px; color:<?= $net_savings >= 0 ? 'var(--ink)' : '#B8447A' ?>">₹<?= number_format($net_savings, 0) ?></div>
    </div>
    <div class="bento-cell">
        <h4>Savings rate</h4>
        <div style="font-family:'Nunito',sans-serif; font-weight:800; font-size:30px;"><?= $savings_rate ?>%</div>
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
            <div class="tracker-bar"><span style="width:<?= $pct ?>%"></span></div>
            <strong>₹<?= number_format($c['total'], 0) ?></strong>
        </div>
        <?php endforeach; endif; ?>
    </div>
    <div class="bento-cell span-2" style="justify-content:center; align-items:flex-start; gap:14px;">
        <h4>🔮 Want to see where this is headed?</h4>
        <p style="font-size:13.5px; font-weight:500; color:var(--ink-soft); line-height:1.5;">
            The Forecast page projects next month's income, expenses and savings using your
            <?= $months_logged ?> month<?= $months_logged == 1 ? '' : 's' ?> of history
            <?= $months_logged < 6 ? 'blended with typical spending patterns from a benchmark dataset' : '' ?>.
        </p>
        <a href="forecast.php" class="btn btn-violet btn-sm">View forecast →</a>
    </div>
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
        <strong style="margin-left:auto; margin-right:16px; color:<?= $t['type'] === 'income' ? '#5C7A17' : '#B8447A' ?>">
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
