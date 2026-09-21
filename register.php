<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) { header('Location: dashboard.php'); exit; }

// ============================================================
// Create an account.
//
// The validation below is UNCHANGED in order and in substance: every
// field required, a real email, at least six characters, the two
// passwords matching, and the email not already taken. Only the wording
// of the messages changed, plus a flag so the "already registered" case
// can offer a way out instead of a dead end.
// ============================================================

$error = '';
$error_is_duplicate = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $pass === '') {
        $error = 'Fill in every field to continue.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "That doesn't look like a valid email address.";
    } elseif (strlen($pass) < 6) {
        $error = 'Your password needs at least 6 characters.';
    } elseif ($pass !== $pass2) {
        $error = "Those two passwords don't match.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account already uses that email.';
            $error_is_duplicate = true;
        } else {
            $colors = ['#4A9A58', '#244833', '#C4645A', '#EFC44A', '#6FBF73', '#7C8A80'];
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

// Keep what they typed. Passwords are never echoed back.
$name_value  = htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES);
$email_value = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#1B3727">
<title>Create your account · Sprout</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght,SOFT,WONK@0,9..144,300..700,0..100,0..1&family=Schibsted+Grotesk:wght@400..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/sprout.css">
</head>
<body>

<div class="auth">

    <!-- ---------- The ground ----------
         A seedling rather than the established plant on the sign-in
         screen. Same drawing, earlier in its life: this is the moment
         before anything has grown. -->
    <aside class="auth-side">
        <a class="auth-mark" href="landing.php">
            <svg width="24" height="28" viewBox="0 0 22 26" aria-hidden="true">
                <path d="M11 25 V11" stroke="var(--shoot)" stroke-width="2.4" fill="none" stroke-linecap="round"/>
                <path d="M11 14 q6-5 10-1 q-5 5-10 1z" fill="var(--lime)"/>
            </svg>
            <span>Sprout</span>
        </a>

        <div class="auth-mid">
            <svg class="auth-plant" width="104" height="128" viewBox="0 0 104 128" aria-hidden="true">
                <line class="gr" x1="16" y1="116" x2="88" y2="116"/>
                <line class="gr" x1="30" y1="124" x2="74" y2="124" opacity=".55"/>
                <!-- Shorter stem, two leaves, and a seed still visible at the base. -->
                <path class="st" style="stroke-dasharray:120; stroke-dashoffset:120;"
                      d="M52 116 C 45 100, 58 90, 52 74"/>
                <path class="lf r" style="animation-delay:1.05s"
                      d="M53 90 q14-10 24-2 q-11 10-24 2z"/>
                <path class="lf" style="animation-delay:1.22s"
                      d="M51 78 q-13-9-22-1 q10 9 22 1z"/>
                <ellipse class="lf" style="animation-delay:.35s; fill:var(--sun);"
                         cx="52" cy="119" rx="7" ry="5"/>
            </svg>

            <h2>Every streak starts at <em>day one</em>.</h2>
            <p>
                Track your habits, your focus and your money in one place. Within a few weeks
                Sprout can show you where they're heading — and what would change it.
            </p>
        </div>

        <p class="auth-foot">Free, and everything you log stays on your own machine.</p>
    </aside>

    <!-- ---------- The form ---------- -->
    <main class="auth-panel">
        <div class="auth-box">
            <h1>Create your account.</h1>
            <p class="lede">One habit is enough to begin with. You can add the rest later.</p>

            <?php if ($error): ?>
            <div class="auth-err" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16h.01"/>
                </svg>
                <span>
                    <?= htmlspecialchars($error) ?>
                    <?php if ($error_is_duplicate): ?> <a href="login.php">Sign in instead</a>.<?php endif; ?>
                </span>
            </div>
            <?php endif; ?>

            <form method="POST" action="register.php" novalidate>
                <div class="field">
                    <label for="name">Your name</label>
                    <input id="name" type="text" name="full_name" value="<?= $name_value ?>"
                           placeholder="What should Sprout call you?" autocomplete="name" required autofocus>
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="<?= $email_value ?>"
                           placeholder="you@example.com" autocomplete="email"
                           autocapitalize="off" spellcheck="false" required>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="pw">
                        <input id="password" type="password" name="password"
                               placeholder="At least 6 characters" autocomplete="new-password"
                               minlength="6" required>
                        <button type="button" id="peek" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                    <p class="hint" id="pwHint">Six characters or more. Longer is better than complicated.</p>
                </div>

                <div class="field">
                    <label for="confirm">Type it once more</label>
                    <input id="confirm" type="password" name="confirm_password"
                           placeholder="The same password again" autocomplete="new-password" required>
                    <p class="hint" id="matchHint" aria-live="polite"></p>
                </div>

                <button type="submit" class="btn btn-go auth-go">Create account</button>
            </form>

            <p class="auth-alt">Already have an account? <a href="login.php">Sign in</a></p>
        </div>
    </main>

</div>

<script>
/* Tell people what's wrong while they type, rather than after they
   submit. The server still checks everything regardless — this only
   saves a round trip. */
(function () {
    const pw = document.getElementById('password');
    const cf = document.getElementById('confirm');
    const pwHint = document.getElementById('pwHint');
    const matchHint = document.getElementById('matchHint');
    const peek = document.getElementById('peek');

    peek?.addEventListener('click', () => {
        const shown = pw.type === 'text';
        pw.type = shown ? 'password' : 'text';
        peek.textContent = shown ? 'Show' : 'Hide';
        peek.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
        peek.setAttribute('aria-pressed', shown ? 'false' : 'true');
        pw.focus();
    });

    function checkLength() {
        if (!pw.value) {
            pwHint.textContent = 'Six characters or more. Longer is better than complicated.';
            pwHint.className = 'hint';
        } else if (pw.value.length < 6) {
            pwHint.textContent = (6 - pw.value.length) + ' more character' +
                (6 - pw.value.length === 1 ? '' : 's') + ' to go.';
            pwHint.className = 'hint warn';
        } else {
            pwHint.textContent = 'That will do nicely.';
            pwHint.className = 'hint ok';
        }
    }

    function checkMatch() {
        if (!cf.value) { matchHint.textContent = ''; matchHint.className = 'hint'; return; }
        const same = pw.value === cf.value;
        matchHint.textContent = same ? 'Both match.' : "These two don't match yet.";
        matchHint.className = 'hint ' + (same ? 'ok' : 'warn');
    }

    pw?.addEventListener('input', () => { checkLength(); checkMatch(); });
    cf?.addEventListener('input', checkMatch);
})();
</script>

</body>
</html>
