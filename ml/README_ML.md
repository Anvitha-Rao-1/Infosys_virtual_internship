# Forecasting & Predictive Analytics — documentation

Sprout has **two separate, independent forecasting engines** — different
languages, different data, different math, on purpose:

| | Engine A — Finance & Habit Forecasting | Engine B — Financial Goal Forecasting |
|---|---|---|
| Where | `ml/forecasting.py` + `ml/train_model.py` (Python) | `includes/helpers.php` (PHP) |
| Predicts | next month's income/expense/profit/cash-flow; next week's completion % / Productivity Score | a savings/debt/investment goal's completion date, risk level, required pace |
| Run when | manually (`python train_model.py`) or via the "↻ Retrain now" button | live, on every page load — no retraining step, always current |
| Method | 2 candidate models, backtested, best one wins | single pace-projection formula with a variability-derived band |
| Shown on | Finance → Forecast tab, Productivity Analysis, Dashboard | Finance → Goals tab |

Both are deliberately simple, explainable math — not a black box — so
every number on the page can be traced back to a specific function and
defended in a report or viva. Neither calls an external AI API.

---

## Engine A — Finance & Habit Forecasting (Python)

This is the Milestone 2 deliverable: *Implement financial forecasting
models · Develop productivity and habit analysis engine · Generate future
trend predictions.* It runs in Python — the PHP app never needs Python to
be *running*, only to have been *run once* to fill in the
`forecast_cache` table, which `finance.php`/`insights.php` simply read.

### What model is this, really?

- **Linear Regression** (scikit-learn) fitted on your month-by-month (or
  week-by-week, for habits) totals, to project a trend forward.
- **ARIMA(1,1,1)** (via `statsmodels`) — a classic time-series model that
  looks at how each period differs from the one before it, rather than
  just fitting a straight line. It needs at least 6 periods of history to
  fit; with less than that (or if it fails to converge) it silently falls
  back to a plain **Moving Average** internally rather than erroring —
  Moving Average is only ever an internal safety net, never itself
  reported as the winning method.
- A **backtest** (`backtest_and_pick_best()` in `forecasting.py`) holds
  out the last up to 3 real periods, refits each of the two candidates on
  everything before, and scores their predictions against what actually
  happened. Whichever has the lower MAE wins and is used to forecast
  forward on the full series — that's where the MAE, RMSE *and* MAPE
  numbers on the Forecast page come from, for *both* candidates
  side-by-side (see the "Why these numbers?" disclosure under each
  chart), not just the winner. Income and expense each pick their own
  best model independently — it's common for one to be better predicted
  by ARIMA and the other by Linear Regression. Series with fewer than 4
  points skip backtesting entirely and default to Linear Regression,
  since a line fit through 2-3 points is the only one of the two that
  degrades gracefully on that little data.
- A **95% confidence interval** is derived from the winning model's own
  backtest RMSE (`confidence_interval()` in `forecasting.py`), widening by
  `sqrt(step)` the further out the forecast reaches under the standard
  random-walk assumption that independent one-step errors compound in
  variance, not linearly. It's drawn as a shaded band under every dashed
  forecast line on the Forecast, Productivity Analysis and Dashboard
  pages (`svg_line_chart()`'s `ci_lower`/`ci_upper` series keys) — so a
  three-month-out projection visibly reads as less certain than next
  month's, instead of both looking equally precise.

  **Why not Facebook Prophet, if the assignment mentions it?** We looked
  at it and deliberately decided against it: Prophet is a heavy
  dependency (pulls in `cmdstanpy`/a compiled Stan backend) that's
  fragile to `pip install` reliably on a typical student's Windows +
  XAMPP + system-Python setup — exactly the install-breaks-in-front-of-
  the-grader risk this project has tried hard to avoid everywhere else.
  ARIMA via `statsmodels` gives the same "real time-series model, not
  just a straight line" story for the report, installs cleanly with one
  `pip install`, and is what's used here instead.

  **Why not Moving Average or Holt-Winters as their own exposed
  candidates?** An earlier version of this engine backtested four
  candidates (Linear Regression, Moving Average, ARIMA, Holt-Winters).
  Moving Average and Holt-Winters were dropped from the exposed
  comparison by request — simpler is easier to defend, and Moving
  Average still does real work as ARIMA's internal fallback above, it
  just never wins on its own anymore.
- **Financial forecasting**: your own transaction **category split** (e.g.
  how much of your spending is Food vs Rent) is blended with the same
  split computed from a **benchmark finance dataset**, weighted so it
  leans more on your own data the more months you've logged (fully your
  own data after 6 months). This solves the "I've only logged 2 weeks, I
  have no idea what my spending even looks like yet" cold-start problem —
  and even with **zero** transactions logged, the Finance tab still shows
  a clearly-labelled preview of the benchmark dataset's own category
  breakdown, so it's never just a blank page.
- **Productivity & habit analysis engine**: a **Productivity Score**
  (0–100) computed per week as `0.6 × completion rate + 0.4 × estimated
  time invested` — where "time invested" comes from each goal's own
  `est_minutes` (a rough per-check-in time estimate you set when creating
  the goal) × how many times you checked in, versus what all your active
  goals would take if you hit every one, every day. That score (and your
  plain completion %) is forecast forward with the same backtested
  two-model approach — no benchmark dataset involved here, since this is
  inherently personal behavioural data. The
  rest of the Productivity & Habits tab (habit-by-habit streaks, time
  allocation by category, best/worst category, most consistent weekday)
  is live, rule-based PHP in `includes/helpers.php` — no retraining
  needed for those, they're just a query away.

- **Profit Margin & Cash Flow** (new): profit margin is projected profit as
  a percentage of projected revenue, per month. Cash flow is a **cumulative
  running total** of every month's profit added together — deliberately a
  different shape of number from "profit" (which is per-month): a rising
  cash-flow line means the running balance is building up, a falling one
  means it's draining down, even in a month where profit itself is
  positive but smaller than before. Both are forecast the same
  backtested way and shown on the Forecast page's KPI row, chart, and
  Forecast Summary table.
- **Focus Session blending** (new): the weekly time-invested figure that
  feeds the Productivity Score now takes the larger of (a) estimated
  minutes from `est_minutes × check-ins` and (b) real minutes actually
  logged via the Focus Sessions timer that week — so a user who has
  started timing their sessions gets credit for their real time, not just
  the rough estimate.
- **Forecast Summary, by model**: alongside the "this month vs.
  next month" table, the Forecast page has a second table showing
  what *each* of the two candidate models (Linear Regression, ARIMA)
  individually predicts for next month's revenue, expense, profit,
  profit margin and cumulative cash flow — computed in
  `build_finance_forecast()` in `train_model.py` and cached under the
  `model_forecasts_next_month` key. This is what actually gets used to
  pick the KPI cards at the top of the page (whichever model backtested
  more accurately per the MAE/RMSE/MAPE table wins), laid out so you can
  see both side-by-side rather than just the winner.
- **Richer chart types** (new): besides the actual/forecast line charts
  (now with confidence bands), `includes/helpers.php` has a radar/spider
  chart (`svg_radar_chart()` — category performance this week vs. last
  week on Insights & Reports, weekday productivity "rhythm" on
  Productivity Analysis, your spending shape vs. the benchmark dataset on
  Forecast), a scatter chart with a fitted trend line and Pearson
  correlation coefficient (`svg_scatter_chart()` — wellness vs. habit
  completion, focus time vs. completion), and a grouped SVG bar chart
  (`svg_grouped_bar_chart()` — this week vs. last week check-ins per
  weekday) for comparisons a single-series bar or line chart can't show.
- **How your data connects to the benchmark dataset** (new): a card on
  the Forecast page spells out, in plain language and with your actual
  numbers, exactly how many months of your own history you've logged,
  what percentage of the category-spend forecast currently comes from
  your own data vs. the benchmark dataset (`user_data_weight_pct` in the
  cached payload — 0% with no history, 100% after 6 months), and a
  worked example using your own top categories. This only affects the
  **category-spend breakdown** — the headline Income vs. Expense
  forecast always uses only your own logged transactions, never the
  benchmark dataset.

No external AI API is called anywhere in this feature.

### Setup (one-time)

### 1. Install Python (skip if already installed)

Download from https://python.org (3.9+). During install, tick **"Add
python.exe to PATH"**.

### 2. Install the Python packages

Open a terminal (Command Prompt / PowerShell), then:

```
cd C:\xampp\htdocs\habit-tracker\ml
pip install -r requirements.txt
```

### 3. The benchmark dataset — already included

`ml/data/finance_benchmark.xlsx` ships with the project, so there's
nothing to download to get started. **It's a synthetic dataset built to
look like a real Kaggle personal-finance download** (same columns, a
realistic 2.5-year date range, seasonal spending patterns) — not real
public data, and `ml/data/README_DATASET.md` says so explicitly and
explains exactly how it was generated. Say this plainly if it comes up in
your report or viva.

Want to swap in a real Kaggle dataset instead (recommended once you have
time — it's a stronger story for "dataset addition" in your milestone
write-up)? Search Kaggle for "daily household transactions", "personal
finance dataset", or "expenses and income dataset", download the CSV or
Excel file, and either replace `finance_benchmark.xlsx` directly or run:

```
python train_model.py --kaggle ml/data/your_file.csv
```

(`.csv`, `.xlsx` and `.xls` all work — the loader detects the format from
the file extension.)

> Different datasets name their columns differently.
> `ml/forecasting.py`'s `load_kaggle_dataset()` already recognises the most
> common variants (Date/date/txn_date, Category/category, Amount/amount,
> "Income/Expense"/type/etc.). If it can't find a column it needs, the
> error message tells you exactly which columns it saw — open
> `forecasting.py` and add your file's exact header name to the matching
> `_..._CANDIDATES` list near the top.

### 4. Log a bit of your own data

In the app, add a handful of transactions on the **Finance** page (a
mix of income and expense, spread across a couple of different dates is
enough to try it — more months of history = better forecasts), and keep
checking in on your goals as usual on the category pages — that's what
feeds the Productivity & Habits tab. If you re-imported `schema.sql`
after updating, every goal (new or existing) has a **"typical time per
check-in"** field (defaults to 20 minutes) — set it honestly per goal, it
directly feeds the Productivity Score and Time Allocation chart.

### 5. Run the training script

```
cd C:\xampp\htdocs\habit-tracker\ml
python train_model.py
```

This connects to your `habit_tracker` MySQL database, reads the benchmark
dataset, computes a forecast for every registered user, and writes the
results into the `forecast_cache` table. You'll see a short summary print
for each user.

### 6. View it

Go to `http://localhost/habit-tracker/finance.php#forecast` (Finance and
Forecast are now one page, two tabs — "This Month" / "Goals" / "Forecast").
There's also a "↻ Retrain now" button on that tab that tries to re-run the
script for you automatically (via PHP's `shell_exec`) — if your XAMPP
setup has that disabled, it'll tell you to just re-run step 5 manually,
which always works.

### Re-running Engine A

Nothing here updates automatically — re-run `python train_model.py`
any time you've added more transactions or check-ins (e.g. right before a
demo) so the forecast reflects your latest data.

---

## Engine B — Financial Goal Forecasting (PHP)

Powers the **Finance → Goals** tab: per-goal savings/debt-payoff/
investment/etc. targets (`financial_goals` table), each with a **Goal
Achievement Forecast**. Unlike Engine A, this needs no training step and
no Python — it's plain PHP, recomputed fresh on every page load from
`goal_contributions`, so it's always exactly as current as your last
logged contribution. All of it lives in `includes/helpers.php`.

### Why a different, simpler method here?

Engine A's backtest-and-pick-winner approach needs several periods of
history per series to be meaningful. A single financial goal realistically
has a handful of contributions, not months of daily data — not enough to
fairly backtest Linear Regression against ARIMA. Rather than force a
method that needs more data than a goal will ever realistically have,
Engine B uses a **pace-projection** approach: the same idea a person does
in their head ("I've been saving about ₹4,000/month, I need ₹40,000 more,
so about 10 months to go") formalized with an honest uncertainty band.

### The math, step by step (`build_goal_forecast()`)

1. **Current amount** = `starting_amount` (what you had saved when you
   created the goal) + the sum of every logged `goal_contributions` row.
   This is never stored on the goal itself — always recomputed — so it
   can never drift out of sync with the real contribution history (same
   "derive, don't store" approach `user_xp()` uses for gamification XP).
2. **Average monthly pace** = the mean of your monthly contribution totals
   over the last up to 6 months.
3. **Variability** = the standard deviation of those same monthly totals.
4. **Projected completion date** = today + however many months it takes
   the *remaining* amount (`target − current`) to be covered at the
   average pace.
5. **Best-case / worst-case dates** = the same projection re-run at
   `avg + 1 stdev` (faster pace → sooner) and `avg − 1 stdev` (slower pace
   → later). A goal contributed to consistently gets a tight best/worst
   band; one contributed to erratically gets an honestly wide one — the
   same philosophy as Engine A's confidence intervals, just built from a
   monthly standard deviation instead of a backtest RMSE.
6. **Risk label** (Low / Medium / High) compares the expected and
   worst-case dates against your actual deadline (`target_date`):
   - **Low** — expected date is on/before the deadline, and even the
     worst case lands within 30 days of it.
   - **Medium** — expected date might miss, but the best case still makes
     the deadline.
   - **High** — even the best-case pace doesn't reach the deadline.
7. **Velocity trend** (accelerating / steady / slowing) compares the first
   half of your contribution window's average to the second half's — a
   >15% swing either way is called out.
8. **Required monthly pace** = the remaining amount ÷ months left until
   the deadline — the number shown as "needed to stay on track", and used
   in the recommendation insight when your current pace won't get you
   there.
9. **Abandonment flag** — separate from the pace-based risk label: if it's
   been 45+ days since your last logged contribution, the goal is flagged
   as possibly stalling, regardless of what the pace math says (a goal you
   stopped touching entirely is a different problem from one you're just
   contributing to slowly).
10. **Burn-down / projection chart series** — the same history + forecast
    + confidence-band shape Engine A's `svg_line_chart()` already draws,
    just fed goal-balance numbers instead of income/expense numbers:
    solid = your real cumulative balance so far, dashed = projected at
    average pace, shaded band = the best/worst-case pace projection.

### Financial Health Score (`financial_health_score()`)

A single 0-100 number shown at the top of the Goals tab, blending four
plain-arithmetic sub-scores (not a trained model):

| Component | Weight | How it's computed |
|---|---|---|
| Savings consistency | 30% | % of the last 6 months that ended with income ≥ expense |
| Spending behaviour | 25% | 100 − (% of those months where expense > income) |
| Goal progress | 25% | average `current_amount / target_amount` across your active financial goals |
| Income stability | 20% | 100 − coefficient of variation of monthly income (steady income scores high, spiky income scores low) |

Any component that can't be computed yet (e.g. no goals yet, or only one
month of income logged) is dropped and the remaining weights are
renormalized — so a new user still gets a meaningful score from whatever
data they do have, rather than a broken one.

### Scenario Simulator

The "what if?" box on each goal card (`runScenario()` in `finance.php`)
re-runs step 4's exact projection **client-side in JavaScript**, with your
hypothetical extra ₹/month added to the average pace and N months zeroed
out to simulate missed contributions — no server round-trip, since it's
the same simple arithmetic the PHP side already computed. This is the
"What if I save ₹500 more/week" / "What if I miss 2 months" style
what-if exploration.

### No retraining needed

Because Engine B is computed live from `goal_contributions` on every page
load, there is no equivalent of "run train_model.py" for it — add a
contribution on the Goals tab and every number (forecast, risk label,
chart, health score) updates immediately on the next page load.
