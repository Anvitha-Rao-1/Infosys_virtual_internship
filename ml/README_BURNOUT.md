# Engine C — Burnout & Wellness Risk Prediction

A third, fully independent forecasting engine, added on top of the two
described in `README_ML.md` (Engine A — Finance & Habit Forecasting, Engine
B — Financial Goal Forecasting). **Nothing in Engine A or B was changed to
build this** — no shared code, no shared dataset, no edits to
`forecasting.py`, `train_model.py`, or `finance_benchmark.xlsx`.

| | Engine C — Burnout & Wellness Risk |
|---|---|
| Where | `ml/burnout_model.py` (Python) |
| Predicts | probability that a user is in a high-stress / burnout-risk state right now |
| Trained on | `ml/data/external/modern_teen_mental_health_main.csv` — 30,000 daily check-ins, 1,000 students, 30 days (external benchmark dataset, not collected by this app) |
| Scored from | the user's own `mood_logs` (mood, sleep, stress) over their last 14 logged days, plus a best-effort look at Exercise/Journal/Meditation-named goal categories |
| Run when | manually (`python burnout_model.py`), same "run once, read many" contract as Engine A |
| Shown on | `burnout.php` |

## Why RandomForestClassifier

`requirements.txt` already pulls in scikit-learn for Engine A. Rather than
add XGBoost/LightGBM/a neural net (a new, heavier dependency, repeating the
exact "fragile pip install on a grader's machine" risk `README_ML.md`
already explains was the reason Prophet was rejected), Engine C uses the
same library the rest of the project already depends on. A shallow random
forest is:
- explainable for free via `feature_importances_` — no extra SHAP install;
- fast enough to train in ~1s and score a user in <1ms;
- not meaningfully less accurate than gradient boosting on a clean,
  well-separated ~30k-row tabular dataset like this one.

## The label, and an honest note about this dataset

`burnout_risk = 1` when `stress_level >= 7` (top ~14% of the benchmark's
0-10 self-reported stress scale). The label is built from `stress_level`
**alone**, and `stress_level` is then excluded from the feature set — this
is what keeps the model an actual predictor instead of restating its own
label.

**Known limitation, stated plainly rather than hidden:** in this
particular synthetic-feeling benchmark dataset, `mood` correlates with
`stress_level` at **r ≈ -0.93**, while every lifestyle feature (sleep,
screen time, exercise, journaling, meditation, social interaction, felt
support) correlates with `stress_level` at **r ≈ 0** — essentially
uncorrelated. That's why `mood` alone carries ~99% of the trained model's
feature importance: it isn't a bug in the code, it's a property of this
dataset (the lifestyle columns appear to have been generated independently
of the stress/mood pair, not causally linked to them). Practically, this
means the model's real, useful finding is **"your reported mood is by far
the strongest predictor of your stress/burnout risk"** — which is a
legitimate, actionable signal (and exactly what the app already asks users
to log every day on the Mood Tracker) — while the lifestyle risk/protective
factors shown alongside it should be read as descriptive context, not as
factors this specific model learned to weigh heavily. A stronger dataset
for the lifestyle-causes-stress story (e.g. one with within-person
day-to-day variation in sleep/screen time and a real, independently-
measured stress outcome) would be a good "future work" swap-in — same
`load_benchmark()` function, same column-detection idea as Engine A's
`load_kaggle_dataset()`.

## Cold-start blending, same philosophy as Engine A

This app has never collected screen time, meditation minutes, or a
1-10 social-support rating. Rather than block the feature on data the app
doesn't have, any missing input is filled from the benchmark dataset's own
population mean and flagged `"from_benchmark": true` in the stored
`payload` and on `burnout.php` — the same "blend your own data with a
benchmark, and say clearly which is which" approach `README_ML.md`
documents for category-spending forecasts.

## Setup

```
cd ml
python burnout_model.py --train-only   # sanity check: trains, prints accuracy/AUC, no DB needed
```

Then, once (per fresh database):

```
cd C:\xampp\htdocs\habit-tracker
"C:\xampp\mysql\bin\mysql.exe" -u root habit_tracker < database\migration_burnout.sql
```

Then, any time you want fresh scores (after logging more mood check-ins):

```
cd ml
python burnout_model.py
```

Visit `http://localhost/habit-tracker/burnout.php`.

## Files this engine owns (all new, nothing pre-existing was edited)

- `ml/burnout_model.py`
- `ml/data/external/*.csv` — all 8 supplied benchmark datasets, copied in
  as-is (only `modern_teen_mental_health_main.csv` is used for training;
  the rest are kept for reference/future features — see Roadmap below)
- `ml/data/external/burnout_model.joblib` — trained model artifact (git-ignore this if you don't want a binary in version control)
- `database/migration_burnout.sql` — one additive `CREATE TABLE IF NOT EXISTS`
- `burnout.php`
- one added `<a>` line in `includes/header.php`'s nav (the only touch to a pre-existing file)

## Roadmap for the other 7 datasets

They weren't used for training (they're aggregate cuts, not per-user daily
rows, so they don't fit a row-level classifier), but they're good fits for
small, low-effort additions:
- `daily_mood_stress_trends.csv` → a faint "population trend" reference
  line behind the user's own mood chart on `mood.php` ("your mood vs. the
  average logged mood that week").
- `screen_vs_sleep_by_age.csv` → a labelled reference point on a
  screen-time-vs-sleep scatter chart, if this app ever starts collecting
  screen time.
- `average_mood_stress_by_gender.csv`, `average_support_feeling_by_country.csv`
  → optional, opt-in peer-comparison context, only if the app ever collects
  gender/country (it currently doesn't, and shouldn't just to power a
  chart) — flagged here rather than built, since collecting demographic
  data you don't otherwise need is a bad trade for a nice-to-have chart.
- `ai_tool_popularity.csv`, `ai_usage_by_country.csv` → out of scope for a
  habit tracker; not recommended for use here at all.
