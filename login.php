<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Incorrect email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log in — Sprout</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-art">
        <span class="auth-badge">🌱 SPROUT</span>
        <h2>Welcome back. Your streaks missed you.</h2>
        <p>Log in to check off today's goals and see how far you've come.</p>
        <div class="auth-blob"></div>
    </div>
    <div class="auth-form-side">
        <div class="auth-card">
            <div class="logo">🌱 Sprout</div>
            <h1>Log in</h1>
            <p class="sub">Pick up your goals where you left off.</p>
            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST" action="login.php">
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="you@example.com" required>
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Your password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Log in</button>
            </form>
            <div class="auth-switch">New here? <a href="register.php">Create an account</a></div>
        </div>
    </div>
</div>
</body>
</html>
