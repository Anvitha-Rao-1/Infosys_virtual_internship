# Sprout — Habit & Goal Tracker

A full-stack habit-tracking web app: registration/login, a dashboard, category
pages (Academic, Study Habits, Personal Habits, Health & Fitness, Work), and a
profile page that shows every goal as a habit-tracker grid with streaks.

**Stack:** HTML + CSS + vanilla JS (frontend) · PHP (backend) · MySQL (database) ·
runs entirely on XAMPP.

---

## 1. Install XAMPP (skip if already installed)

Download from https://www.apachefriends.org and install it. You already have
this set up from your TechFinance project, so you can reuse it.

## 2. Copy the project into htdocs

1. Open your XAMPP install folder (Windows: `C:\xampp`).
2. Go into `htdocs`.
3. Copy the whole `habit-tracker` folder in here, so the path looks like:
   `C:\xampp\htdocs\habit-tracker\`

## 3. Start Apache and MySQL

1. Open **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.
   (Both rows should turn green.)

## 4. Create the database

1. In the Control Panel, click **Admin** next to MySQL — this opens
   **phpMyAdmin** in your browser (`http://localhost/phpmyadmin`).
2. Click the **Import** tab at the top.
3. Click **Choose file**, and select `database/schema.sql` from this project.
4. Scroll down and click **Go**.
5. You should now see a new database called **habit_tracker** in the left
   sidebar, with 4 tables: `users`, `categories`, `goals`, `goal_logs`.

> The database connection settings are in `includes/db.php`. XAMPP's default
> MySQL user is `root` with **no password**, which is already set — you don't
> need to change anything unless you customised your MySQL install.

> **Already set the project up before?** The category colours were updated to
> match the new look. Just re-import `database/schema.sql` in phpMyAdmin the
> same way — it's safe to run again and only refreshes the 5 category colours,
> it won't touch your users, goals, or check-in history.

## 5. Open the app

Go to:

```
http://localhost/habit-tracker/
```

You'll land on the login page. Click **Create an account**, register, and
you'll be taken straight to your dashboard.

---

## How the site is organised

```
habit-tracker/
├── database/
│   └── schema.sql        ← import this once, in step 4
├── includes/
│   ├── db.php             ← database connection
│   ├── auth.php           ← login/session helper functions
│   ├── helpers.php        ← streak & weekly % calculations
│   ├── header.php         ← shared sidebar + topbar (all logged-in pages)
│   └── footer.php         ← shared closing markup
├── css/
│   └── style.css          ← all styling (one design system)
├── js/
│   └── app.js              ← check-in toggling (AJAX), toasts, modal
├── index.php               ← redirects to login or dashboard
├── register.php            ← sign up
├── login.php                ← log in
├── logout.php                ← destroys session
├── dashboard.php             ← overview: stats, category cards, recent goals
├── profile.php                ← edit name, full habit-tracker of ALL your goals
├── category.php                ← shared engine that powers each category page
├── academic.php   ─┐
├── study.php        │ each of these is its own real page/URL that loads
├── habits.php        │ category.php with a fixed category — so every
├── fitness.php        │ category is a distinct webpage as you asked
└── work.php          ─┘
└── toggle_log.php            ← AJAX endpoint: marks a day done/undone
```

## What's stored in the database

- **users** — name, email, hashed password (passwords are never stored in
  plain text), created date.
- **categories** — the 5 fixed categories, seeded automatically by
  `schema.sql`.
- **goals** — every goal a user adds: title, description, category, target
  frequency, created date.
- **goal_logs** — one row per day a goal was checked off. This is what powers
  the weekly grid, the streak counter, and the completion percentage ring.

## Adding a goal

Go to any category page (from the sidebar) → **+ Add goal** → fill in the
title, description, frequency, and how many days a week you're aiming for.

## Checking in

On any category page or your profile, click a day box (M T W T F S S) next to
a goal to mark it done for that day — it saves instantly via AJAX, no page
reload. Click again to undo it. Future days are locked.

## Customising

- **Colours / fonts:** edit the `:root` variables at the top of
  `css/style.css`.
- **Categories:** add/edit rows in the `categories` table via phpMyAdmin —
  new categories will automatically need a matching `slug.php` wrapper file
  (copy `academic.php` and change the slug) and a sidebar link in
  `includes/header.php`.

## Troubleshooting

| Problem | Fix |
|---|---|
| "Database connection failed" | Make sure MySQL is started (green) in the XAMPP Control Panel, and that you imported `schema.sql`. |
| Blank white page | Open XAMPP Control Panel → Apache → **Logs** → `php_error_log` to see the exact error. |
| Styles look broken | Make sure the folder is named exactly `habit-tracker` inside `htdocs`, since the CSS/JS paths are relative. |
| Port 80 already in use | In XAMPP Control Panel, click **Config** next to Apache and switch to port 8080, then visit `http://localhost:8080/habit-tracker/`. |

## What's new: Mood, Analytics, Rewards, and AI Coach

The site now goes beyond simple goal tracking:

- **landing.php** — a public marketing homepage (hero, features, how-it-works, testimonials, CTA). This is now what `index.php` shows to logged-out visitors instead of jumping straight to the login form.
- **mood.php** — daily mood check-in (6 moods), optional sleep hours + stress level, a 28-day mood calendar, a wellness score (0–100, blended from mood + sleep + stress over the last 7 days), and a mood check-in streak.
- **analytics.php** — a 12-week GitHub-style check-in heatmap, a 7-day bar chart, a 5-week completion-% bar chart, a consistency score, and auto-generated insight cards (best/worst category, most consistent weekday).
- **gamification.php ("Rewards")** — a real XP/level system (10 XP per check-in, plus bonus XP for achievements), 3 weekly challenges with live progress bars, and 9 unlockable achievement badges.
- **coach.php ("AI Coach")** — personalized recommendations generated by analysing your *own* logged data: goals still pending today, streaks about to break, your weakest category this week, a mood-vs-completion correlation, and a 2-week trend comparison.
  > **Important for your Q&A:** this is a **rule-based recommendation engine**, not a live call to an external AI/LLM API. It reads real patterns out of your MySQL data with plain PHP logic (comparisons, averages, simple correlation) — everything it says can be traced back to a specific SQL query in `coach.php`. This is worth mentioning if you're asked "is this really AI" in your presentation — it's honest, explainable logic rather than a black box, which is usually a stronger answer in an academic setting anyway.

### New database tables

| Table | Stores |
|---|---|
| `mood_logs` | one row per user per day — mood, sleep hours, stress level, note |
| `achievements` | the fixed catalogue of 9 unlockable badges (seeded automatically) |
| `user_achievements` | which badges each user has actually earned, and when |

If you already have the database set up, just **re-import `database/schema.sql`** in phpMyAdmin — it only creates the new tables (`CREATE TABLE IF NOT EXISTS`) and refreshes category colours; your users, goals, and check-in history are untouched.

XP itself is **not stored anywhere** — it's calculated live every time from `COUNT(check-ins) × 10 + achievement bonuses`, so it can never drift out of sync with your real activity, and levels update instantly as soon as you check in.
