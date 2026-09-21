<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/narrative.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'Settings';
$nav = 'overview';

// ============================================================
// SETTINGS — your account.
//
// All three POST handlers (profile, biodata, password) are UNCHANGED,
// including the password check, the duplicate-email guard and the
// six-character minimum.
// ============================================================

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $avatar_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['avatar_color'] ?? '') ? $_POST['avatar_color'] : $user['avatar_color'];
    if ($name === '') {
        $errors[] = 'Your name cannot be empty.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That does not look like a valid email address.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>?");
        $check->execute([$email, $uid]);
        if ($check->fetch()) {
            $errors[] = 'Another account is already using that email.';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, avatar_color=? WHERE id=?");
            $stmt->execute([$name, $email, $avatar_color, $uid]);
            $user = current_user();
            $success = 'Saved.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_biodata'])) {
    $birthdate = $_POST['birthdate'] ?? '';
    $gender = trim($_POST['gender'] ?? '');
    $birthdate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate) ? $birthdate : null;
    $gender = $gender !== '' ? $gender : null;
    $stmt = $pdo->prepare("UPDATE users SET birthdate=?, gender=? WHERE id=?");
    $stmt->execute([$birthdate, $gender, $uid]);
    $user = current_user();
    $success = 'Saved.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        $errors[] = 'That current password is not right.';
    } elseif (strlen($new) < 6) {
        $errors[] = 'Your new password needs at least 6 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'The two new passwords do not match.';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
        $success = 'Password changed.';
    }
}

$age = null;
if (!empty($user['birthdate'])) {
    $age = (new DateTime($user['birthdate']))->diff(new DateTime('today'))->y;
}
$growth = nar_growth($pdo, $uid);
$since = date('F Y', strtotime($user['created_at']));
$colors = ['#4A9A58', '#244833', '#C4645A', '#EFC44A', '#6FBF73', '#7C8A80'];

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">Settings</p>
            <h1>Your account.</h1>
            <p class="hero-sub">
                With Sprout since <?= $since ?>, and <?= number_format($growth['checkins']) ?> check-ins in.
            </p>
        </div>
    </div>
</header>

<div class="wrap">

<?php if ($success): ?><div class="flash" style="margin-top:34px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?>
<div class="flash" style="margin-top:<?= $success ? '10px' : '34px' ?>; background:var(--clay);"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<section class="ch enter" style="padding-top:<?= ($success || $errors) ? '28px' : '44px' ?>;">
    <div class="ch-head"><h2>You</h2></div>
    <p class="ch-lead">How you appear inside Sprout.</p>

    <div class="setgrid">
        <form method="POST" class="panel">
            <div class="field">
                <label for="s-name">Name</label>
                <input id="s-name" type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>
            </div>
            <div class="field">
                <label for="s-email">Email</label>
                <input id="s-email" type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <div class="field">
                <label>Your colour</label>
                <div class="swatches">
                    <?php foreach ($colors as $c): ?>
                    <label class="swatch <?= strcasecmp($user['avatar_color'], $c) === 0 ? 'on' : '' ?>"
                           style="background:<?= $c ?>" title="<?= $c ?>">
                        <input type="radio" name="avatar_color" value="<?= $c ?>" style="display:none;"
                               <?= strcasecmp($user['avatar_color'], $c) === 0 ? 'checked' : '' ?>
                               onchange="document.querySelectorAll('.swatch').forEach(s=>s.classList.remove('on'));this.parentNode.classList.add('on');">
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" name="update_profile" class="btn btn-go" style="margin-top:6px;">Save</button>
        </form>

        <form method="POST" class="panel">
            <div class="field">
                <label for="s-dob">Date of birth</label>
                <input id="s-dob" type="date" name="birthdate" max="<?= date('Y-m-d') ?>"
                       value="<?= htmlspecialchars($user['birthdate'] ?? '') ?>">
                <?php if ($age !== null): ?>
                <p style="font-size:12.5px; color:var(--ink-soft); margin-top:7px;">That makes you <?= $age ?>.</p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="s-gender">Gender</label>
                <input id="s-gender" type="text" name="gender" placeholder="However you'd describe it"
                       value="<?= htmlspecialchars($user['gender'] ?? '') ?>">
            </div>
            <p style="font-size:12.5px; color:var(--ink-soft); line-height:1.6; margin-bottom:16px;">
                Both are optional and neither is shared with anyone. They only sit on your own account.
            </p>
            <button type="submit" name="update_biodata" class="btn btn-line">Save</button>
        </form>
    </div>
</section>

<section class="ch enter">
    <div class="ch-head"><h2>Password</h2></div>
    <p class="ch-lead">You'll need your current one to set a new one.</p>

    <form method="POST" class="panel" style="max-width:520px;">
        <div class="field">
            <label for="p-cur">Current password</label>
            <input id="p-cur" type="password" name="current_password" required>
        </div>
        <div class="row2">
            <div class="field">
                <label for="p-new">New password</label>
                <input id="p-new" type="password" name="new_password" minlength="6" required>
            </div>
            <div class="field">
                <label for="p-con">Again, to be sure</label>
                <input id="p-con" type="password" name="confirm_password" minlength="6" required>
            </div>
        </div>
        <button type="submit" name="change_password" class="btn btn-go">Change password</button>
    </form>
</section>

<section class="ch enter">
    <div class="ch-head"><h2>Your data</h2></div>
    <p class="ch-lead">What Sprout holds, and where it goes.</p>
    <div class="panel">
        <table class="facts">
            <tr><td>What's stored</td><td>Your habits, check-ins, moods, focus sessions and transactions — on this machine, in your own database.</td></tr>
            <tr><td>Who sees it</td><td>Only you. There is no shared account and nothing is sold or synced anywhere.</td></tr>
            <tr><td>What leaves</td><td>Only when you press "Explain this" or ask a question: a short list of already-calculated figures goes to the writing service. Never your raw records. You can see the exact list each time.</td></tr>
            <tr><td>Your password</td><td>Scrambled before it is saved, so it cannot be read back — not even by this app.</td></tr>
        </table>
        <p style="margin-top:18px;"><a class="more" href="methodology.php">How Sprout works out its numbers</a></p>
    </div>
</section>

</div>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
