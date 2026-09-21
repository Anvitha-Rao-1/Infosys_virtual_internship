# Sprout — A Plain-English Guide to the Whole Codebase

Written for someone opening this project for the first time. No prior
knowledge of the code is assumed, and every technical term is explained the
first time it appears.

If you only read one section, read **"The big idea in 60 seconds"** and
**"The map"**.

---

## The big idea in 60 seconds

Sprout is a personal tracker with three sides — **habits**, **money**, and
**productivity** — and a prediction layer on top.

You use it in three steps:

1. **You log things.** Check off habits, record your mood, time your study
   sessions, enter income and expenses.
2. **The app learns your pattern and predicts what's next.** Not magic — it
   fits simple statistical models to your own history and projects them
   forward.
3. **You ask "what if?"** Move a slider — save more, study longer, sleep more
   — and the app shows you what the *same* model predicts for that changed
   input, then has an AI write a plain-English explanation of the difference.

The whole project is built around one rule:

> **Numbers come from maths. Words come from the AI. Never the other way
> around.**

Every figure you see was calculated by a statistical model. The AI is only
ever handed finished numbers and asked to describe them. It is never allowed
to produce a number itself.

---

## What it's built with

| Piece | What it is | Why it's here |
|---|---|---|
| **PHP** | A language that builds web pages on the server | Runs the website — every page you click |
| **MySQL** | A database | Stores your goals, check-ins, moods, money |
| **Python** | A language good at maths and data | Runs the prediction models |
| **XAMPP** | A bundle of Apache (web server) + MySQL | Lets all this run on your own laptop |
| **Hugging Face** | A website hosting AI language models | Writes the plain-English explanations |

There is **no framework** (no Laravel, no React). It's plain PHP, plain
JavaScript, and hand-written CSS. That makes it easy to follow: what you see
in a file is what runs.

### An important design choice: PHP and Python never talk directly

This trips people up, so it's worth stating clearly.

The website (PHP) **never runs Python while you're browsing.** Instead:

1. You run a Python script manually (or press a button that tries to).
2. Python does the slow maths and **saves its answers into the database**.
3. The website just **reads those saved answers** and draws them.

Why? Because calling Python on every page load would be slow and fragile — if
Python weren't installed, the whole site would break. This way the site always
works; it just shows older predictions until you re-run the scripts.

The database tables ending in `_cache` exist for exactly this reason.

---

## Getting it running

```bash
# 1. Start Apache and MySQL in the XAMPP control panel.

# 2. Create the database — import these in phpMyAdmin, in this order:
database/schema.sql              # the main tables
database/migration_burnout.sql   # adds the burnout table
database/migration_whatif.sql    # adds the what-if + AI tables
database/migration_coach_chat.sql # adds the AI Coach chat history

# 3. Install the Python libraries
pip install -r ml/requirements.txt

# 4. Optional — only needed for AI-written explanations
copy .env.example .env           # then paste a Hugging Face token into it

# 5. Load demo data (optional but recommended — see "Demo data" below)
C:\xampp\php\php.exe ml\seed_demo_users.php

# 6. Build the predictions — ORDER MATTERS
python ml/train_model.py         # forecasts
python ml/burnout_model.py       # burnout risk
python ml/whatif_engine.py       # what-if scenarios (needs the two above)
```

Then open `http://localhost/habit-tracker` (or `:8080` if Apache uses that
port).

**Why the order matters:** `whatif_engine.py` reads what the other two
scripts write. Run it first and it has nothing to work from.

---

## The map — every file, one line each

### Pages you can visit

| File | Lines | What it does |
|---|---|---|
| `index.php` | 4 | The front door. Sends you to the dashboard if logged in, the landing page if not. |
| `landing.php` | 112 | The marketing page strangers see. |
| `register.php` | 86 | Create an account. Checks the email is valid and the password is 6+ characters. |
| `login.php` | 63 | Log in. Compares your password against the stored scrambled version. |
| `logout.php` | 5 | Ends your session. |
| `dashboard.php` | 153 | Your daily home: today's habits, one progress ring, one trend chart. |
| `habits.php` | 184 | Where you tick habits off. Shows streaks and the current week. |
| `goals.php` | 210 | Create, edit and retire habits (called "goals" in the code). |
| `mood.php` | 188 | Log today's mood, hours slept, and stress level. |
| `focus.php` | 266 | A study timer, plus a form to log a session you already did. |
| `finance.php` | 295 | Enter income and expenses. Also handles the financial-goal forms. |
| `calendar.php` | 90 | A month grid showing which days you checked in. |
| `reminders.php` | 126 | Set reminder times. **Note: these display in-app only — nothing sends emails or notifications.** |
| `gamification.php` | 83 | XP, levels and achievement badges. |
| `coach.php` | 310 | **The AI Coach.** A chat box you can ask questions in, plus rule-based tips below it. |
| `analyse.php` | 1148 | **The big analysis page.** Three tabs — Productivity, Habits, Finance — with all the charts and forecasts. |
| `simulate.php` | 747 | **The What-If Lab.** Sliders, scenario comparison, buy-vs-rent, AI insights. |
| `methodology.php` | 458 | "How was this calculated?" — explains the models, reading real values from your account. |
| `insights.php`, `burnout.php` | 9, 8 | Old addresses. They just redirect into `analyse.php` so old bookmarks don't break. |

### Behind-the-scenes endpoints

These aren't pages you visit — JavaScript calls them in the background and
they reply with data, not HTML.

| File | Lines | What it does |
|---|---|---|
| `toggle_log.php` | 44 | Ticks a habit on or off when you click a day box. |
| `focus_log.php` | 36 | Saves a finished focus session. |
| `coach_chat.php` | 69 | Answers one AI Coach question and saves the conversation. |
| `ai_insight.php` | 111 | Asks the AI to explain a scenario, and replies with the text. |
| `run_forecast.php` | 37 | The "Retrain now" button — tries to run the Python forecaster. |
| `run_whatif.php` | 45 | The "Re-simulate" button — tries to run the Python what-if engine. |

### Shared code (`includes/`)

Every page loads these. They hold the code that would otherwise be copy-pasted.

| File | Lines | What it does |
|---|---|---|
| `db.php` | 24 | Opens the database connection. **Edit this if your MySQL port isn't 3307.** |
| `auth.php` | 29 | Login checks — "is someone logged in?", "who are they?", "kick them out if not". |
| `helpers.php` | 1595 | **The workhorse.** 61 functions: every database query and every chart-drawing routine. |
| `chart_card.php` | 97 | One consistent box to put a chart in, so every chart on the site looks the same. |
| `header.php` / `footer.php` | 61 / 6 | The sidebar, top bar and closing tags wrapped around every page. |
| `icons.php` | 39 | The sidebar icons, drawn as code rather than image files. |
| `whatif.php` | 374 | Reads saved what-if scenarios and formats them. Also holds the buy-vs-rent calculator. |
| `ai_insight.php` | 486 | Talks to Hugging Face. Also contains the backup writer used when the AI is unavailable. |
| `coach_ai.php` | 478 | Builds the AI Coach's facts sheet, its prompt, and its local fallback answers. |
| `env.php` | 63 | Reads secrets (the AI key) from the `.env` file, so they're never written into the code. |

### The prediction scripts (`ml/`)

| File | Lines | What it does |
|---|---|---|
| `forecasting.py` | 508 | The maths toolkit: three prediction methods, plus data cleaning and scoring. |
| `train_model.py` | 572 | Reads your data, builds forecasts, saves them to the database. |
| `burnout_model.py` | 405 | Trains a burnout-risk classifier and scores every user. |
| `whatif_engine.py` | 893 | **The what-if layer.** Feeds modified inputs to the *existing* models. |
| `evaluate_forecast.py` | 172 | A test script — checks how accurate the money forecasts actually are. |
| `evaluate_habit_forecast.py` | 139 | The same, for habit forecasts. |
| `seed_demo_users.php` | 181 | Creates two fake users with months of realistic history, for demos. |

### Everything else

| File | What it does |
|---|---|
| `css/style.css` (737) | All the styling. Colours are defined once at the top as variables. |
| `js/app.js` (164) | Browser behaviour: ticking habits, animated numbers, tab switching. |
| `database/*.sql` | Instructions that create the database tables. |
| `.env` | Your secret AI key. **Never goes into Git.** |
| `.env.example` | A template showing which settings exist, with the values blank. |

---

## The database, in plain words

Think of each table as a spreadsheet.

### Things about you

| Table | One row = | Notes |
|---|---|---|
| `users` | One account | Passwords are stored *scrambled* (hashed), never as readable text. |
| `categories` | One habit type | Fixed list of 5: Academic, Study Habits, Personal Habits, Health & Fitness, Work & Productivity. |
| `goals` | One habit you're tracking | "Morning workout", "Read 20 pages". `est_minutes` = roughly how long it takes. |
| `goal_logs` | One day you completed one habit | The core of the whole app. One row per habit per day. |
| `mood_logs` | One day's mood entry | Mood, hours slept, stress level. Max one per day. |
| `focus_sessions` | One study/work session | Planned vs actual minutes. |
| `reminders` | One reminder you set | Display only — nothing is sent anywhere. |

### Money

| Table | One row = |
|---|---|
| `transactions` | One income or expense entry |
| `financial_goals` | One savings target ("₹50,000 emergency fund by December") |
| `goal_contributions` | One payment toward a savings target |

A detail worth knowing: **how much you've saved is never stored.** It's added
up fresh from the contributions each time. That way it can't drift out of
sync with reality.

### Rewards

| Table | One row = |
|---|---|
| `achievements` | One badge that exists (9 total) |
| `user_achievements` | One badge you've earned |

XP is also never stored — it's recalculated as **10 XP per check-in + badge
bonuses**, and every **150 XP** is one level.

### The prediction caches

These four are the "Python writes, PHP reads" tables described earlier.

| Table | Written by | Holds |
|---|---|---|
| `forecast_cache` | `train_model.py` | Your money and habit forecasts |
| `burnout_predictions` | `burnout_model.py` | Your burnout risk score and its explanation |
| `whatif_cache` | `whatif_engine.py` | Every what-if scenario, pre-calculated |
| `ai_insight_cache` | `includes/ai_insight.php` | AI explanations already written, so they aren't paid for twice |
| `coach_messages` | `coach_chat.php` | Your AI Coach conversation, so it survives a reload |

---

## The four "brains"

The project has four separate prediction systems. They're independent — if one
breaks, the others keep working.

### Brain 1 — The forecaster (money, habits, productivity)

**Question it answers:** "If I carry on like this, where do I end up?"

It tries **three different prediction methods** and picks whichever has been
most accurate *for you*:

| Method | In plain words |
|---|---|
| **Linear Regression** | Draws the straightest line through your history and extends it. |
| **ARIMA** | Assumes this month partly depends on last month, and follows that thread forward. |
| **XGBoost** | Learns rules of thumb from patterns in your recent values. |

**How it decides which to trust — "backtesting":** it hides your last few
months, asks each method to predict them, then compares the guesses to what
actually happened. The method that was closest wins. This is why the app can
tell you *how accurate* it is — it has genuinely been tested.

It also draws a **shaded band** around predictions. The band is how uncertain
the model is, and it widens further into the future — because guessing next
month is easier than guessing three months out.

Files: `ml/forecasting.py` (maths) + `ml/train_model.py` (database work).

### Brain 2 — The burnout classifier

**Question it answers:** "Does my recent pattern look like a stressed one?"

This one is different: it was trained on **other people's data** — a public
dataset of 30,000 daily check-ins from 1,000 students — and then applied to
you. It uses a **Random Forest**: hundreds of simple yes/no decision trees
that vote, and the share of votes becomes your risk percentage.

⚠️ **Be honest about this one.** Checking the model's own internals shows that
**`mood` accounts for 99% of its decision**, and mood is nearly the same thing
as the stress level it's trying to predict. So it's closer to a mood detector
than a genuine behavioural predictor. This is written up properly in
`docs/PROJECT_REPORT.md` §A4 — read it before anyone asks.

File: `ml/burnout_model.py`.

### Brain 3 — The what-if engine

**Question it answers:** "What if I changed something?"

This is the newest part, and the simplest idea in the project:

> Take the model that already exists. Give it a **changed version of your
> history**. Write down what it says.

It does **not** build a new model. It imports the existing ones and feeds them
different input. For example, for savings:

1. Work out what you currently save (say 46%).
2. Rewrite your expense history as if you'd saved 55% instead.
3. Run that rewritten history through **the same forecaster**.
4. Save the answer.

It does this for every slider position ahead of time — 12 to 15 positions per
category — so the sliders respond instantly.

**One rule worth knowing:** the slider only stops on values the model was
actually run on. It never invents an in-between answer.

Each category gets three labelled scenarios:

- **Expected** — carry on as you are
- **Improved** — a realistic step better
- **Risk** — a realistic step worse

The burnout slider is the clearest example of the whole idea: it loads the
already-trained Random Forest, takes the feature list that model already
produced for you, **changes one number**, and asks again.

File: `ml/whatif_engine.py`.

### Brain 4 — The AI writer (Hugging Face)

**Question it answers:** "What do all these numbers actually mean?"

This is the only part that isn't maths. It gets about **twenty finished
numbers** and writes three to five sentences about them.

Things it is *not* allowed to do, and how that's enforced:

| Rule | How it's enforced |
|---|---|
| Never invent a number | The instructions say so explicitly |
| Never calculate anything | Every figure — including the differences and percentages — is worked out in PHP first and handed over as plain text |
| Never see your private data | Only those ~20 finished numbers are sent. No transactions, no check-ins. The app shows you the exact list. |

**If the AI is unavailable, nothing breaks.** No key, bad key, no internet,
rate limit — the app writes the explanation itself using built-in rules,
labels it **"Written locally"** instead of "Written by …", and every
prediction, scenario and chart is exactly as it was.

Files: `includes/ai_insight.php` (the logic) + `ai_insight.php` (the endpoint).

### Brain 5 — The AI Coach chat

**Question it answers:** whatever you type.

This is the chat box on the Coach page. It works on exactly the same
principle as Brain 4, with one extra step: before your question is sent, the
app gathers **about 45 real numbers** about you — streaks, sleep, spending,
forecasts, burnout factors, what-if results — and puts them in the AI's
instructions as a **facts sheet**.

The AI is then told: answer using only these facts, and if something isn't
there, say so instead of guessing.

That last part matters more than it sounds. A normal chatbot asked *"how much
did I spend on coffee in March 2024?"* will cheerfully invent a number. This
one replies *"I don't have that tracked yet"* — because the honest answer is
genuinely in its instructions and the invented one isn't.

The facts come from the **same functions the rest of the app displays**, so
the coach can never quote a different figure than your dashboard.

If Hugging Face is unavailable, a keyword matcher answers from the same facts
sheet — less chatty, same numbers — and the reply is tagged *"written
locally"*.

Files: `includes/coach_ai.php` + `coach_chat.php`.

---

## How everything connects

```
   YOU USE THE APP                    PYTHON RUNS (separately)
   ───────────────                    ────────────────────────
   tick a habit      ──┐
   log your mood     ──┤
   record a session  ──┼──►  MySQL  ──►  train_model.py    ──► forecast_cache
   enter an expense  ──┘       ▲         burnout_model.py  ──► burnout_predictions
                               │         whatif_engine.py  ──► whatif_cache
                               │                                    │
                               └────────────────────────────────────┘
                                             │
                                             ▼
                              PHP pages read the saved answers
                                             │
                        ┌────────────────────┴────────────────────┐
                        ▼                                         ▼
                  analyse.php                              simulate.php
               (charts, forecasts)                    (sliders, scenarios)
                                                              │
                                                              ▼
                                                   ~20 numbers sent to
                                                      Hugging Face
                                                              │
                                                              ▼
                                                   plain-English explanation
```

### What happens when you tick a habit

A concrete walk-through of one click:

1. You click a day box in `habits.php`.
2. `js/app.js` notices the click and quietly sends the habit ID and date to
   `toggle_log.php`.
3. `toggle_log.php` checks you're logged in, checks the habit is **yours**,
   and refuses future dates.
4. If a record exists for that day it's deleted; if not, one is added.
   (That's why it's called *toggle*.)
5. It recalculates your week percentage and checks whether you earned a badge.
6. It sends those back; JavaScript updates the ring and shows a message —
   **without reloading the page**.

Notice steps 3: **every endpoint re-checks ownership.** The browser is never
trusted to say what belongs to you.

---

## Where to look when you want to...

| I want to... | Go here |
|---|---|
| Change a colour or spacing | `css/style.css` — colours are variables at the top |
| Fix the database connection | `includes/db.php` |
| Add a link to the sidebar | `includes/header.php` |
| Change a chart | Find the `svg_*_chart()` function in `includes/helpers.php` |
| Add a new statistic | Write a function in `includes/helpers.php`, call it from the page |
| Change how forecasts work | `ml/forecasting.py` |
| Add a new what-if slider | `ml/whatif_engine.py` — copy an existing `build_*_whatif()` function |
| Change what the AI is told | `AI_SYSTEM_PROMPT` in `includes/ai_insight.php` |
| Change what the Coach knows about you | `coach_facts()` in `includes/coach_ai.php` |
| Change the Coach's rules | `coach_system_prompt()` in `includes/coach_ai.php` |
| Swap the AI model | `HF_MODEL` in `.env` |
| Understand a number on screen | `methodology.php` in the app, or `docs/PROJECT_REPORT.md` |

---

## Demo data

The app is much easier to show with history behind it. `ml/seed_demo_users.php`
creates two fake users with about six months of realistic data:

| Login | Password | Profile |
|---|---|---|
| `demo.riya@sprout.test` | `Demo@1234` | Consistent. High savings, strong habits, low burnout risk. |
| `demo.karan@sprout.test` | `Demo@1234` | Struggling. Spends everything, patchy habits, **high** burnout risk. |

Two are included on purpose — they demonstrate opposite behaviour, including
one flat burnout curve and one that responds.

The seeder **shifts all dates forward so the data always ends today**. This
matters: if data goes stale, the most recent complete week is empty, and the
forecaster correctly predicts a collapse to zero. That's the model working
properly on bad input — but it looks broken. Re-running the seeder fixes it.

⚠️ Re-seeding **deletes and recreates** those two accounts, so their user IDs
change and their saved predictions are wiped. Re-run the three Python scripts
afterwards. **Your own account is never touched** — the seeder only matches
the two `@sprout.test` addresses.

---

## Things that are easy to misunderstand

**"Goals" means two different things.** In `goals.php` and the `goals` table,
a goal is a *habit* ("Morning workout"). In `financial_goals`, it's a *savings
target*. Different things, similar names.

**Reminders don't remind you.** They're stored and displayed in the app. No
email or push notification is sent. `database/schema.sql` says so too.

**Buy-vs-rent is not a prediction.** It's ordinary loan arithmetic. The
forecasting model was trained on monthly income and expense totals — it has
never seen a property price, so asking it would mean making something up. It's
deliberately kept out of the `ml/` folder, and labelled as a calculation
everywhere it appears.

**The Productivity Score is a formula, not a model.** It's defined as
`0.6 × completion rate + 0.4 × time invested`. The machine learning happens
when that score is *predicted forward*, not when it's worked out.

**The finance benchmark data is synthetic.** The file
`ml/data/finance_benchmark.csv` was generated by a script, not downloaded. It
has realistic shape, but it isn't real public data. A genuine Kaggle file is
also bundled but isn't used by default. (`ml/data/README_DATASET.md` states
this too.)

**Your data never trained anything.** The burnout model learned from a public
dataset and is merely *applied* to you. The forecasters are fitted to your own
history at the moment of prediction — nothing is learned from you and kept for
anyone else.

---

## The other documents

| Document | Read it when |
|---|---|
| `README.md` | You're installing the project |
| `docs/CODE_GUIDE.md` | ← you are here — understanding the codebase |
| `docs/WHATIF_AND_AI.md` | You need the technical detail of the what-if and AI layers |
| `docs/PROJECT_REPORT.md` | You're presenting or defending the project (includes likely questions and answers) |
| `ml/README_ML.md` | You're working on the forecasting models |
| `ml/README_BURNOUT.md` | You're working on the burnout model |
| `ml/data/README_DATASET.md` | You want to know where the training data came from |

---

## A short glossary

| Term | Plain meaning |
|---|---|
| **Backtesting** | Hiding part of your history, predicting it, and checking the guess. How accuracy is measured. |
| **Model** | A formula that turns inputs into a prediction. |
| **Training** | Showing a model examples so it can work out its formula. |
| **Feature** | One input a model uses (hours of sleep, last month's spending). |
| **Classification** | Predicting a category ("high risk" / "low risk"). |
| **Regression** | Predicting a number (₹22,000). |
| **Confidence interval** | The shaded band — the range the true answer probably sits in. |
| **MAE / RMSE** | Two ways of measuring how far off predictions were, on average. |
| **Cache** | A saved answer, kept so it doesn't have to be worked out again. |
| **Endpoint** | A URL that replies with data instead of a web page. |
| **Hashing** | Scrambling a password one-way, so it can be checked but never read back. |
| **LLM** | A Large Language Model — an AI that writes text. |
| **Prompt** | The instructions given to an LLM. |
| **What-if analysis** | Asking a model what it *would* have predicted if an input were different. |
