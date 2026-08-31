<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) { header('Location: dashboard.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sprout — Habits that actually stick</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<nav class="landing-nav">
    <div class="brand">
        <span class="brand-mark">🌱</span>
        <span class="brand-name">Sprout</span>
    </div>
    <div style="display:flex; gap:12px; align-items:center;">
        <a href="login.php" class="btn btn-ghost btn-sm">Log in</a>
        <a href="register.php" class="btn btn-primary btn-sm">Get started</a>
    </div>
</nav>

<section class="landing-hero">
    <h1>Small habits, <span>tracked daily</span>, grow into real change</h1>
    <p class="lead">Sprout helps you build academic, study, fitness, and personal habits with streaks, mood check-ins, and gentle insights — no pressure, no guilt, just steady progress.</p>
    <div class="landing-hero-actions">
        <a href="register.php" class="btn btn-primary">Get started free</a>
        <a href="login.php" class="btn btn-ghost">I already have an account</a>
    </div>
    <div class="landing-mascot"></div>
</section>

<section class="landing-section">
    <h2>Why people stick with Sprout</h2>
    <div class="feature-grid">
        <div class="feature-card">
            <div class="f-ico" style="background:var(--lime);">🎯</div>
            <h4>5 goal categories</h4>
            <p>Academic, study habits, personal habits, fitness, and work — organised so nothing gets lost.</p>
        </div>
        <div class="feature-card">
            <div class="f-ico" style="background:var(--sky);">🔥</div>
            <h4>Real streak tracking</h4>
            <p>Every check-in is saved to your history — streaks and weekly percentages update instantly.</p>
        </div>
        <div class="feature-card">
            <div class="f-ico" style="background:var(--pink);">💛</div>
            <h4>Mood & wellness</h4>
            <p>Log how you're feeling alongside your habits, and see how they connect over time.</p>
        </div>
        <div class="feature-card">
            <div class="f-ico" style="background:var(--lavender);">🏆</div>
            <h4>Levels & achievements</h4>
            <p>Earn real XP for every check-in, unlock badges, and complete weekly challenges.</p>
        </div>
    </div>
</section>

<section class="landing-section" style="background:var(--offwhite); border-radius: var(--radius-lg);">
    <h2>How it works</h2>
    <div class="feature-grid" style="grid-template-columns: repeat(3,1fr);">
        <div class="feature-card">
            <div class="f-ico" style="background:var(--mint,var(--sky));">1️⃣</div>
            <h4>Add your goals</h4>
            <p>Pick a category, name your goal, and set how often you want to do it.</p>
        </div>
        <div class="feature-card">
            <div class="f-ico" style="background:var(--lime);">2️⃣</div>
            <h4>Check in daily</h4>
            <p>One tap marks a day done — your streak and weekly ring update instantly.</p>
        </div>
        <div class="feature-card">
            <div class="f-ico" style="background:var(--pink);">3️⃣</div>
            <h4>See your growth</h4>
            <p>Analytics, mood trends, and your AI coach show you what's working.</p>
        </div>
    </div>
</section>

<section class="landing-section">
    <h2>What people are saying</h2>
    <div class="testimonial-grid">
        <div class="testimonial-card" style="background:var(--lime);">
            <p>"I've tried a dozen habit apps. This is the first one where I actually kept a 30-day streak."</p>
            <div class="t-who">— Early user, Study Habits</div>
        </div>
        <div class="testimonial-card" style="background:var(--sky);">
            <p>"The mood check-ins made me realise I skip workouts most on my most stressful days — now I know to go easier on myself then."</p>
            <div class="t-who">— Early user, Health & Fitness</div>
        </div>
        <div class="testimonial-card" style="background:var(--pink);">
            <p>"Levelling up for check-ins sounds silly but it genuinely gets me to open the app every morning."</p>
            <div class="t-who">— Early user, Academic</div>
        </div>
    </div>
</section>

<section class="landing-cta">
    <h2>Ready to grow better habits?</h2>
    <p>It takes less than a minute to create your first goal.</p>
    <a href="register.php" class="btn btn-primary">Get started free</a>
</section>

<footer class="landing-footer">🌱 Sprout — a habit tracker built for real, steady progress.</footer>

</body>
</html>
