<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) { header('Location: dashboard.php'); exit; }

// ============================================================
// Sign in.
//
// The handler below is UNCHANGED, including the deliberately vague
// failure message: it never says whether it was the email or the
// password that was wrong, because saying so would tell a stranger
// which addresses have accounts.
// ============================================================

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
        $error = "That email and password don't match an account.";
    }
}
// Keep what they typed, so a wrong password doesn't cost them the email too.
$email_value = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#1B3727">
<title>Sign in · Sprout</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght,SOFT,WONK@0,9..144,300..700,0..100,0..1&family=Schibsted+Grotesk:wght@400..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/sprout.css">
</head>
<body>

<div class="auth">

    <!-- ---------- The ground ---------- -->
    <aside class="auth-side">
        <a class="auth-mark" href="landing.php">
            <svg width="24" height="28" viewBox="0 0 22 26" aria-hidden="true">
                <path d="M11 25 V11" stroke="var(--shoot)" stroke-width="2.4" fill="none" stroke-linecap="round"/>
                <path d="M11 14 q6-5 10-1 q-5 5-10 1z" fill="var(--lime)"/>
            </svg>
            <span>Sprout</span>
        </a>

        <div class="auth-mid">
            <!-- Draws itself once on arrival. One orchestrated moment, not a loop. -->
            <svg class="auth-plant" width="104" height="128" viewBox="0 0 104 128" aria-hidden="true">
                <line class="gr" x1="16" y1="116" x2="88" y2="116"/>
                <line class="gr" x1="30" y1="124" x2="74" y2="124" opacity=".55"/>
                <path class="st" d="M52 116 C 40 92, 64 70, 52 34"/>
                <path class="lf r" style="animation-delay:1.15s"
                      d="M53 82 q16-11 27-2 q-12 11-27 2z"/>
                <path class="lf" style="animation-delay:1.32s"
                      d="M51 62 q-16-11-27-2 q12 11 27 2z"/>
                <path class="lf r" style="animation-delay:1.49s"
                      d="M53 46 q13-9 22-1 q-10 9-22 1z"/>
            </svg>

            <h2>Small changes. <em>Visible growth.</em></h2>
            <p>
                Your habits, your money and your focus in one place — with an honest view of where
                they're heading, and what would change it.
            </p>
        </div>

        <p class="auth-foot">Everything you log stays on your own machine.</p>
    </aside>

    <!-- ---------- The form ---------- -->
    <main class="auth-panel">
        <div class="auth-box">
            <h1>Welcome back.</h1>
            <p class="lede">Pick up where you left off — your streaks are still running.</p>

            <?php if ($error): ?>
            <div class="auth-err" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16h.01"/>
                </svg>
                <span><?= htmlspecialchars($error) ?> Check them and try again.</span>
            </div>
            <?php endif; ?>

            <form method="POST" action="login.php" novalidate>
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="<?= $email_value ?>"
                           placeholder="you@example.com" autocomplete="email"
                           autocapitalize="off" spellcheck="false" required
                           <?= $error ? '' : 'autofocus' ?>>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="pw">
                        <input id="password" type="password" name="password"
                               placeholder="Your password" autocomplete="current-password" required
                               <?= $error ? 'autofocus' : '' ?>>
                        <button type="button" id="peek" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-go auth-go">Sign in</button>
            </form>

            <p class="auth-alt">New to Sprout? <a href="register.php">Create an account</a></p>
        </div>
    </main>

</div>

<script>
/* Let people check what they typed. Real products do this, and it turns
   a failed sign-in into a fixable one. */
(function () {
    const btn = document.getElementById('peek');
    const input = document.getElementById('password');
    if (!btn || !input) return;
    btn.addEventListener('click', () => {
        const shown = input.type === 'text';
        input.type = shown ? 'password' : 'text';
        btn.textContent = shown ? 'Show' : 'Hide';
        btn.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
        btn.setAttribute('aria-pressed', shown ? 'false' : 'true');
        input.focus();
    });
})();
</script>

</body>
</html>
