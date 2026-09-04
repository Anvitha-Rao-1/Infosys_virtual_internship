# Forecasting & Predictive Analytics — setup (one-time)

This adds a **Finance** page (log income/expenses) and a two-tab
**Forecast** page to Sprout — this is the Milestone 2 deliverable:
*Implement financial forecasting models · Develop productivity and habit
analysis engine · Generate future trend predictions.* The forecasting
itself runs in Python — the PHP app never needs Python to be *running*,
only to have been *run once* to fill in the `forecast_cache` table.

## What model is this, really?

Nothing fancy, and that's on purpose — it's explainable and you can defend
every number in a viva:

- **Linear Regression** (scikit-learn) fitted on your month-by-month (or
  week-by-week, for habits) totals, to project a trend forward.
- **Moving Average** as a second candidate.
- **ARIMA(1,1,1)** (via `statsmodels`) as a third candidate — a classic
  time-series model that looks at how each period differs from the one
  before it, rather than just fitting a straight line. It needs at least
  6 periods of history to fit; with less than that it silently falls back
  to Moving Average rather than erroring.
- A **backtest** (predict the last few real periods using only earlier
  ones, compare to what actually happened) picks whichever of the three
  was more accurate — that's where the MAE, RMSE *and* MAPE numbers on
  the Forecast page come from, for *all three* candidates side-by-side
  (see the "Why these numbers?" disclosure under each chart), not just
  the winner. Income and expense each pick their own best model
  independently — it's common for one to be better predicted by ARIMA
  and the other by Linear Regression.

  **Why not Facebook Prophet, if the assignment mentions it?** We looked
  at it and deliberately decided against it: Prophet is a heavy
  dependency (pulls in `cmdstanpy`/a compiled Stan backend) that's
  fragile to `pip install` reliably on a typical student's Windows +
  XAMPP + system-Python setup — exactly the install-breaks-in-front-of-
  the-grader risk this project has tried hard to avoid everywhere else.
  ARIMA via `statsmodels` gives the same "real time-series model, not
  just a straight line" story for the report, installs cleanly with one
  `pip install`, and is what's used here instead.
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
  Linear Regression / Moving Average approach — no benchmark dataset
  involved here, since this is inherently personal behavioural data. The
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
- **Forecast Summary, by model** (new): alongside the "this month vs.
  next month" table, the Forecast page now has a second table showing
  what *each* of the three candidate models (Linear Regression, Moving
  Average, ARIMA) individually predicts for next month's revenue,
  expense, profit, profit margin and cumulative cash flow — computed in
  `build_finance_forecast()` in `train_model.py` and cached under the
  `model_forecasts_next_month` key. This is what actually gets used to
  pick the KPI cards at the top of the page (whichever model backtested
  more accurately per the MAE/RMSE/MAPE table wins), laid out so you can
  see all three side-by-side rather than just the winner.
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

## 1. Install Python (skip if already installed)

Download from https://python.org (3.9+). During install, tick **"Add
python.exe to PATH"**.

## 2. Install the Python packages

Open a terminal (Command Prompt / PowerShell), then:

```
cd C:\xampp\htdocs\habit-tracker\ml
pip install -r requirements.txt
```

## 3. The benchmark dataset — already included

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

## 4. Log a bit of your own data

In the app, add a handful of transactions on the **Finance** page (a
mix of income and expense, spread across a couple of different dates is
enough to try it — more months of history = better forecasts), and keep
checking in on your goals as usual on the category pages — that's what
feeds the Productivity & Habits tab. If you re-imported `schema.sql`
after updating, every goal (new or existing) has a **"typical time per
check-in"** field (defaults to 20 minutes) — set it honestly per goal, it
directly feeds the Productivity Score and Time Allocation chart.

## 5. Run the training script

```
cd C:\xampp\htdocs\habit-tracker\ml
python train_model.py
```

This connects to your `habit_tracker` MySQL database, reads the benchmark
dataset, computes a forecast for every registered user, and writes the
results into the `forecast_cache` table. You'll see a short summary print
for each user.

## 6. View it

Go to `http://localhost/habit-tracker/forecast.php`. There's also a
"↻ Retrain now" button on that page that tries to re-run the script for
you automatically (via PHP's `shell_exec`) — if your XAMPP setup has that
disabled, it'll tell you to just re-run step 5 manually, which always
works.

## Re-running later

Nothing here updates automatically — re-run `python train_model.py`
any time you've added more transactions or check-ins (e.g. right before a
demo) so the forecast reflects your latest data.
