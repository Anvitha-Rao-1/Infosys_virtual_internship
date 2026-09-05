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

## What's new: Finance tracking + Forecasting & Predictive Analytics

This is the Milestone 2 feature: **Implement financial forecasting
models · Develop productivity and habit analysis engine · Generate
future trend predictions.**

- **finance.php ("Finance")** — log income/expenses by category, see this
  month's income/expense/savings/savings-rate, and a category spending
  breakdown.
- **forecast.php ("Forecast")** — two tabs:
  - **💰 Finance** — projects next 1–3 months of income, expenses and
    savings, with an actual-vs-forecast chart, a projected category
    breakdown, and a collapsible model-performance comparison (MAE/RMSE
    for both candidate models, not just the winner).
  - **🌱 Productivity & Habits** — the productivity-and-habit-analysis
    engine: a computed **Productivity Score** (0–100, blending your
    weekly completion rate with an estimated time-invested figure),
    forecast forward the same way as the finance numbers; a **Time
    Allocation** donut showing where your estimated time went by
    category this week; a goal-by-goal **Habit Overview** (this week's
    % and current streak); and insight cards (best/worst category, most
    consistent weekday, trend commentary).
  > **Important for your Q&A:** the *forecasting* half is a real, if
  > intentionally simple, ML pipeline (`ml/train_model.py`) — four
  > candidate models (**scikit-learn Linear Regression**, **Moving
  > Average**, **ARIMA**, and **Holt-Winters** exponential smoothing),
  > backtested against your own recent history to pick whichever
  > predicts better, for both the finance numbers and the productivity
  > score — plus a 95% confidence band derived from that backtest, drawn
  > as a shaded region around every forecast line. The *analysis* half (habit overview, time allocation,
  > best/worst category) is live, explainable PHP logic in
  > `includes/helpers.php` — the same "rule-based, not a black box"
  > philosophy as `coach.php`, computed fresh on every page load with no
  > retraining needed. The finance forecast additionally blends your own
  > transaction categories with category patterns learned from a
  > **benchmark personal-finance dataset** (solves the "I just started
  > tracking, I have no history" cold-start problem — and even before
  > you log a single transaction, the Finance tab shows a clearly-labelled
  > preview of the benchmark's own category breakdown, so the dataset is
  > visibly doing something from day one). That benchmark
  > (`ml/data/finance_benchmark.xlsx`) is **synthetic** — generated to have
  > a realistic shape, not downloaded from Kaggle — and
  > `ml/data/README_DATASET.md` says so plainly; swap in a real Kaggle
  > file any time by following that same doc. No external AI API is
  > called anywhere — see `ml/README_ML.md` for the one-time setup and
  > `ml/forecasting.py` for every formula.

### New database tables & columns (Finance + Forecast)

| Table / column | Stores |
|---|---|
| `transactions` | every income/expense entry: type, category, amount, date, note |
| `forecast_cache` | the latest forecast JSON per user, written by `ml/train_model.py`, read by `forecast.php` |
| `goals.est_minutes` | a self-reported "typical minutes per check-in" per goal (default 20), set on the Add Goal form — powers the Productivity Score and Time Allocation chart |

Re-import `database/schema.sql` once to create the new tables/column
(same safe `IF NOT EXISTS` pattern as always), then follow
`ml/README_ML.md` to generate your first forecast.

## What's new: full navigation rebuild, Focus Sessions, Goals, Calendar, Reminders & Settings

The sidebar was reorganised into **Track** (Activity Tracker, Habit Tracker,
Mood Tracker, Focus Sessions, Goals), **Analyze** (Productivity Analysis,
Insights & Reports, Finance, Forecast), **Plan** (Calendar View, Reminders),
**More** (Rewards, AI Coach) and **Account** (Settings). A few pages were
consolidated or replaced along the way:

- **activity.php ("Activity Tracker")** replaces the separate
  `academic.php` / `study.php` / `habits.php` / `fitness.php` / `work.php`
  links — one page, with a category filter tab strip (`?cat=slug`), showing
  every goal's day-box grid and streak ring across all categories in one
  place. Those old files still exist on disk (untouched) but nothing in the
  new nav links to them any more.
- **goals.php ("Goals")** — a dedicated place to see, add, edit and delete
  every goal (title, description, category, typical minutes per session),
  separate from day-to-day check-ins (which still happen on Activity
  Tracker).
- **habit_tracker.php ("Habit Tracker")** — Habit Score (a 4-week rolling
  completion average), best current streak, total lifetime check-ins, a
  12-week check-in heatmap, and every habit's weekly % + streak in one list.
- **focus.php ("Focus Sessions") + focus_log.php** — a real, working
  countdown timer: pick an optional goal and a duration (15/25/45/60 min or
  custom), start it, and it counts down live in the browser. Finishing or
  giving up early logs a row to the new `focus_sessions` table with
  server-computed start/end times (never trusted from the browser clock),
  and the KPI row (sessions this week, average length, success rate, total
  time) is calculated fresh from real logged sessions — nothing here is
  fabricated.
- **productivity.php ("Productivity Analysis")** — the productivity-and-
  habit-analysis engine now lives here on its own page (previously a tab on
  Forecast): Productivity Score, Habit Score, tasks completed today, current
  streak, focus time this week, weekly goal progress, the backtested
  Productivity Score forecast chart, a Time Allocation donut (now blending
  real Focus Session minutes with each goal's estimate — whichever is
  larger — via `category_time_allocation()`), an insights summary, a 12-week
  activity heatmap, and Focus Sessions / Habit Overview summary cards.
- **calendar.php ("Calendar View")** — a month grid coloured by daily
  check-in volume, with the day's mood emoji shown when one was logged.
- **reminders.php ("Reminders")** — simple recurring reminders (title, time,
  days of week, optional linked goal) that show up as a "Today" list on the
  Dashboard and on the Reminders page itself. Sprout does **not** send
  emails, texts or push notifications for these yet — the page says so
  plainly so it's never mistaken for something it isn't.
- **settings.php ("Settings")** replaces `profile.php` for account
  management: update name/email/avatar colour, biodata (date of birth +
  gender — age is computed live from the date of birth), and change
  password (current-password check, minimum length, confirm-match).
- **mood.php** gained a Week / Month / All-time stats toggle
  (`?range=week|month|all`) with a matching mood-distribution breakdown, on
  top of the existing daily check-in and wellness score.
- **forecast.php ("Forecast")** is now Finance-only (the productivity tab
  moved to Productivity Analysis above) and gained the metrics from the
  Milestone 2 brief that weren't on it yet: **Profit Margin** (profit as %
  of revenue), **Cash Flow** (a cumulative running cash-position chart, fed
  by monthly profit — distinct from the per-month Profit figure), and a
  **Forecast Summary** table (Revenue / Expense / Profit / Profit Margin /
  Cash Flow, this month vs. next month) plus a renamed **Top Insights**
  section.

### New database tables & columns (Phase 3)

| Table / column | Stores |
|---|---|
| `focus_sessions` | one row per finished/interrupted focus session: user, optional goal, planned vs. actual minutes, status, server-computed start/end times |
| `reminders` | title, time, days of week (as `Mon,Tue,...`), optional linked goal, active/paused flag |
| `users.birthdate`, `users.gender` | biodata set from Settings; age is computed on the fly, never stored |

Re-import `database/schema.sql` once — it's the same safe
`CREATE TABLE IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS` pattern as always,
so your existing users, goals, transactions and check-in history are
untouched. Then re-run `python ml/train_model.py` once (see
`ml/README_ML.md`) — the training script now also blends real
`focus_sessions` minutes into the weekly time-invested figure it feeds the
Productivity Score forecast, so a retrain picks that up.

### The bundled benchmark dataset is now bigger

`ml/data/finance_benchmark.xlsx` was regenerated at **2,378 rows** spanning
January 2015 – June 2025 (10.5 years), up from the original 472-row / ~1-year
version — still the same clearly-labelled **synthetic** dataset described in
`ml/data/README_DATASET.md`, just with more history for the forecasting
models to learn from.

## What's new: more data-entry points, more graphs, and a third forecasting model

This round closes three gaps: pages that only *displayed* data with no way to
add it, sections that were still visual placeholders rather than being wired
to real data, and the forecasting model set only having two candidates.

- **Habit Tracker now lets you check in directly.** Every habit card on
  `habit_tracker.php` has its own Mon–Sun day-box strip (the same widget
  Activity Tracker uses) — tap a box to check that day done/not done without
  leaving the page. No new JavaScript needed: it reuses the global
  delegated click handler in `js/app.js` that already powers Activity
  Tracker's day boxes.
- **Focus Sessions now has a manual-entry form.** Not every session gets
  timed live — the new "Log a past session instead" link on `focus.php`
  opens a form (goal, date, time, minutes, completed/interrupted) that
  inserts straight into `focus_sessions`, so past work isn't lost just
  because the timer wasn't running.
- **Insights & Reports was fully rebuilt**, not just renamed. It now pulls
  from live queries across the whole app — a "Category comparison this
  week" bar chart, an 8-week "Mood & wellness trend" line chart, a full
  "Financial snapshot" section (income/expense/profit KPIs + a 6-month
  trend chart), an auto-generated plain-language weekly report (assembled
  from the same numbers shown on the page — no ML, no external AI), and a
  **Print report** button (`window.print()` with a print-only stylesheet
  that hides the sidebar and buttons).
- **Finance gained two more charts:** a category-breakdown donut (same
  categories as the existing bar list, easier to scan at a glance) and a
  full-width "Income vs. expenses — last 6 months" line chart, both built
  from your own live transactions (`monthly_transaction_totals()` in
  `includes/helpers.php` — no retraining needed, they update the moment
  you log a transaction).
- **Dashboard gained three more charts:** a "This week's check-ins" bar
  chart, a compact "Time allocation" donut + legend, and a "Finance
  snapshot" mini-card linking to Forecast — the Dashboard went from 2
  charts to 5.
- **Productivity Analysis gained trend-delta badges and two new mini
  cards.** The Productivity Score, Habit Score, Tasks Completed, and Focus
  Time KPI cards each now show a small "▲ +6% vs last week" / "▼ −1 vs
  last Friday" style badge (green = up, red = down, grey = flat/no prior
  data), and the Current Streak card shows your all-time personal-best
  streak alongside the live one. Two new cards — **Top Productive Day**
  and **Least Productive Day** — show which weekday you complete the most
  vs. least of your habits on average (a genuine 0–100 score per weekday
  over the last 8 weeks, via the new `weekday_productivity_scores()`
  helper — not just a raw all-time check-in count). A small **Today's
  Quote** widget rounds out the row (a static list, picked deterministically
  by day-of-year — no external API).
- **Forecast gained a third model — ARIMA — and two new cards.** See
  "What model is this, really?" in `ml/README_ML.md` for the full
  rationale (including why Prophet was considered and rejected). In
  short: Linear Regression, Moving Average and ARIMA are all backtested,
  and the "Why these numbers?" table now compares all three (with MAE,
  RMSE, *and* MAPE). A new **"Forecast summary — next month, by model"**
  card shows what each of the three models individually predicts for
  revenue/expense/profit/margin/cash flow, and a new **"How your data
  connects to the benchmark dataset"** card explains — with your actual
  numbers and a worked example — exactly how your logged transactions and
  the benchmark dataset combine to produce the category-spend forecast.

### New helpers in `includes/helpers.php` (round 2)

| Function | Purpose |
|---|---|
| `weekly_checkin_bars()` | This week's check-ins per day (Dashboard bar chart) |
| `wellness_score_weekly()` | Weekly-bucketed wellness score trend (Insights mood chart) |
| `monthly_transaction_totals()` | Live month-by-month income/expense (Finance & Insights charts) |
| `category_completion_this_week()` | Full category comparison, sorted (Insights bar chart) |
| `short_label()` | Dependency-free label truncation (no `mbstring` required) |
| `habit_score_prev_week()` | Habit Score computed one week back, for the trend-delta badge |
| `focus_minutes_prev_week()` | Prior week's focus minutes, for the Focus Time trend-delta badge |
| `tasks_completed_on()` | Check-ins completed on a given date, for the Tasks Completed delta |
| `trend_delta()` / `trend_delta_count()` | Builds the "▲ +6% vs last week" badge data |
| `all_time_best_streak()` | Longest streak any goal has ever reached, for the Current Streak card |
| `weekday_productivity_scores()` | Per-weekday average completion score (Top/Least Productive Day) |
| `daily_quote()` | Static rotating quote for the Productivity Analysis page |

No new database tables were needed for any of this — it's all built from
existing `goals`, `goal_logs`, `focus_sessions`, `mood_logs` and
`transactions` data, plus the ML-cached `forecast_cache` payload's new
`model_forecasts_next_month`, `user_data_weight_pct` and
`months_of_history_used` fields (written by `ml/train_model.py` — re-run it
after updating, see `ml/README_ML.md`).

### Analytics upgrade — a fourth forecasting model, confidence bands, and richer chart types

- **Forecasting gained a fourth candidate model — Holt-Winters exponential
  smoothing (damped trend)** — alongside Linear Regression, Moving Average
  and ARIMA, backtested the same way for both finance and productivity/
  habit forecasts. It tends to track real (non-straight-line) habit and
  spending data more accurately than a plain trend line, without a moving
  average's blindness to trend. See `ml/README_ML.md` for the full
  rationale.
- **Every forecast line now carries a 95% confidence band**, derived from
  the winning model's own backtest RMSE and widened by `sqrt(step)` the
  further out it projects (`confidence_interval()` in `ml/forecasting.py`).
  Drawn as a shaded region under the dashed forecast line by
  `svg_line_chart()` on the Forecast, Productivity Analysis and Dashboard
  pages — profit and cumulative cash flow combine income's and expense's
  margins in quadrature rather than getting a falsely tight band of their
  own.
- **Three new SVG chart types in `includes/helpers.php`**, used where a
  single-series bar or line chart couldn't show the real comparison:
  - `svg_radar_chart()` — category completion this week vs. last week
    (Insights & Reports), weekday productivity "rhythm" across all seven
    days at once (Productivity Analysis), and your spending shape vs. the
    benchmark dataset's (Forecast).
  - `svg_scatter_chart()` — a fitted trend line plus Pearson correlation
    coefficient for wellness score vs. habit completion (Insights &
    Reports) and focus time vs. completion (Productivity Analysis).
  - `svg_grouped_bar_chart()` — this week vs. last week check-ins per
    weekday (Insights & Reports), replacing a single-week bar chart with
    a direct side-by-side comparison.
- **New helpers feeding those charts**: `category_completion_prev_week()`,
  `weekly_checkin_bars_compare()`, `wellness_completion_correlation()`,
  `focus_completion_daily()`, and a shared `forecast_method_label()` used
  by both Forecast's and Productivity Analysis's "why these numbers?"
  model comparison tables (now listing all four candidate models, not
  three).
- The finance forecast payload also now exposes the raw
  `category_shares_user_pct` / `category_shares_benchmark_pct` splits
  (previously only the already-blended dollar forecast was cached), which
  is what the new "spending shape vs. benchmark" radar chart plots.
