<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $pass === '') {
        $error = 'Please fill in every field.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($pass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $colors = ['#6C63A6','#2F5233','#E17B5B','#3F7D58','#4F86C6'];
            $color = $colors[array_rand($colors)];
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, avatar_color) VALUES (?,?,?,?)");
            $stmt->execute([$name, $email, $hash, $color]);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create your account — Sprout</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-art">
        <span class="auth-badge">🌱 SPROUT</span>
        <h2>Go for better habits with Sprout</h2>
        <p>Track your academic goals, study habits, fitness and personal routines — all in one place.</p>
        <div class="auth-blob"></div>
    </div>
    <div class="auth-form-side">
        <div class="auth-card">
            <div class="logo">🌱 Sprout</div>
            <h1>Create your account</h1>
            <p class="sub">Start tracking the goals that matter to you.</p>
            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST" action="register.php">
                <div class="field">
                    <label>Full name</label>
                    <input type="text" name="full_name" placeholder="Sigma Anvitha" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="At least 6 characters" required>
                </div>
                <div class="field">
                    <label>Confirm password</label>
                    <input type="password" name="confirm_password" placeholder="Re-enter password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Create account</button>
            </form>
            <div class="auth-switch">Already have an account? <a href="login.php">Log in</a></div>
        </div>
    </div>
</div>
</body>
</html>
