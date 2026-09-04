# Finance + Habit Forecasting — setup (one-time)

This adds a **Finance** page (log income/expenses) and a **Forecast** page
(ML-projected income, expenses, savings, and habit completion rate) to
Sprout. The forecasting itself runs in Python — the PHP app never needs
Python to be *running*, only to have been *run once* to fill in the
`forecast_cache` table.

## What model is this, really?

Nothing fancy, and that's on purpose — it's explainable and you can defend
every number in a viva:

- **Linear Regression** (scikit-learn) fitted on your month-by-month income
  and expense totals, to project a trend forward.
- **Moving Average** as the alternative candidate.
- A **backtest** (predict the last few real months using only earlier
  months, compare to what actually happened) picks whichever of the two
  was more accurate — that's where the MAE/RMSE numbers on the Forecast
  page come from.
- Your own transaction **category split** (e.g. how much of your spending
  is Food vs Rent) is blended with the same split computed from a
  **benchmark finance dataset**, weighted so it leans more on your own data
  the more months you've logged (fully your own data after 6 months). This
  solves the "I've only logged 2 weeks, I have no idea what my spending
  even looks like yet" cold-start problem.
- Habit/goal completion-rate forecasting uses the same Linear
  Regression/Moving-Average approach on your own weekly check-in history —
  no benchmark dataset involved there, since that's inherently personal
  behavioural data.

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
enough to try it — more months of history = better forecasts).

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
