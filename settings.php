<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();
$user = current_user();
$uid = $user['id'];
$page_title = 'Settings';
$active = 'settings';

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $avatar_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['avatar_color'] ?? '') ? $_POST['avatar_color'] : $user['avatar_color'];
    if ($name === '') {
        $errors[] = 'Name cannot be empty.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>?");
        $check->execute([$email, $uid]);
        if ($check->fetch()) {
            $errors[] = 'That email is already in use by another account.';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, avatar_color=? WHERE id=?");
            $stmt->execute([$name, $email, $avatar_color, $uid]);
            $user = current_user();
            $success = 'Profile updated.';
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
    $success = 'Biodata updated.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
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

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="profile-head anim-in">
    <span class="avatar" style="background:<?= htmlspecialchars($user['avatar_color'] ?? '#6C63A6') ?>; width:76px; height:76px; font-size:26px;"><?= initials($user['full_name']) ?></span>
    <div>
        <h2><?= htmlspecialchars($user['full_name']) ?></h2>
        <p><?= htmlspecialchars($user['email']) ?><?= $age !== null ? ' · ' . $age . ' years old' : '' ?><?= !empty($user['gender']) ? ' · ' . htmlspecialchars($user['gender']) : '' ?></p>
    </div>
</div>

<div class="bento-grid" style="grid-template-columns: repeat(2, 1fr);">
    <div class="bento-cell anim-in" style="grid-column: span 1;">
        <h4>Profile</h4>
        <form method="POST">
            <div class="field">
                <label>Full name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <div class="field">
                <label>Avatar colour</label>
                <input type="color" name="avatar_color" value="<?= htmlspecialchars($user['avatar_color'] ?? '#6C63A6') ?>" style="width:60px; height:40px; padding:2px; border-radius:10px; border:1.5px solid var(--lightgray);">
            </div>
            <button type="submit" name="update_profile" class="btn btn-primary" style="margin-top:6px;">Save profile</button>
        </form>
    </div>

    <div class="bento-cell anim-in" style="grid-column: span 1;">
        <h4>Biodata</h4>
        <form method="POST">
            <div class="field">
                <label>Date of birth</label>
                <input type="date" name="birthdate" value="<?= htmlspecialchars($user['birthdate'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="field">
                <label>Gender</label>
                <select name="gender">
                    <option value="">Prefer not to say</option>
                    <?php foreach (['Female', 'Male', 'Non-binary', 'Other'] as $g): ?>
                    <option value="<?= $g ?>" <?= ($user['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="update_biodata" class="btn btn-primary" style="margin-top:6px;">Save biodata</button>
        </form>
    </div>
</div>

<div class="card anim-in" style="max-width:520px;">
    <h4 style="text-transform:uppercase; font-size:13px; color:var(--ink-soft); letter-spacing:.04em; margin-bottom:14px;">Change password</h4>
    <form method="POST">
        <div class="field">
            <label>Current password</label>
            <input type="password" name="current_password" required>
        </div>
        <div class="field">
            <label>New password</label>
            <input type="password" name="new_password" minlength="6" required>
        </div>
        <div class="field">
            <label>Confirm new password</label>
            <input type="password" name="confirm_password" minlength="6" required>
        </div>
        <button type="submit" name="change_password" class="btn btn-primary" style="margin-top:6px;">Change password</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
