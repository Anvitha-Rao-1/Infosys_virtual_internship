"""
train_model.py
---------------
Run this whenever you want to refresh forecasts (once is enough for a demo;
re-run any time you've logged more transactions or check-ins).

What it does, in order:
  1. Connects to your existing XAMPP MySQL database (habit_tracker).
  2. Loads a benchmark personal-finance dataset from ml/data/ — by default
     the synthetic one bundled at ml/data/finance_benchmark.xlsx (see
     ml/data/README_DATASET.md for exactly how it was generated and how to
     swap in a real Kaggle CSV/XLSX instead) — and learns general
     category-spending proportions from it. This is the "benchmark" that
     fills in for categories you haven't logged much of yourself yet.
  3. For every user in the app:
       - Pulls their own `transactions` rows, builds a monthly income/expense
         forecast blended with the benchmark, backtests two simple models
         (linear trend vs moving average) and keeps whichever predicted
         recent months more accurately.
       - Pulls their own `goal_logs` (habit check-ins), builds a weekly
         completion-rate forecast the same way — no benchmark data involved
         here, since this is inherently personal behaviour.
     Both results are written as JSON into the `forecast_cache` table.
  4. finance.php / forecast.php simply SELECT the latest row and render it —
     no PHP page ever calls Python directly, so nothing in the live web app
     depends on Python being installed or running.

Usage:
    python train_model.py
    python train_model.py --kaggle ml/data/my_real_kaggle_download.csv

Requires: pip install -r requirements.txt   (see README_ML.md)
"""

import argparse
import json
import os
import sys
import warnings
from datetime import date

import pandas as pd

# pandas prints a harmless warning when reading SQL through mysql-connector
# instead of SQLAlchemy — suppressed so it doesn't look like something broke.
warnings.filterwarnings("ignore", message="pandas only supports SQLAlchemy")

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from forecasting import (
    load_kaggle_dataset,
    monthly_totals,
    category_shares,
    blend_category_shares,
    forecast_series,
    forecast_weekly_completion,
)

try:
    import mysql.connector
except ImportError:
    print("Missing dependency. Run:  pip install -r requirements.txt")
    sys.exit(1)


DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "habit_tracker",
}

FORECAST_MONTHS_AHEAD = 3
FORECAST_WEEKS_AHEAD = 2
DEFAULT_KAGGLE_PATH = os.path.join(os.path.dirname(__file__), "data", "finance_benchmark.xlsx")


def connect_db():
    try:
        return mysql.connector.connect(**DB_CONFIG)
    except mysql.connector.Error as e:
        print(f"Could not connect to MySQL: {e}")
        print("Make sure XAMPP's MySQL is running and the habit_tracker database exists.")
        sys.exit(1)


def load_benchmark(kaggle_path: str):
    if not os.path.exists(kaggle_path):
        print(f"No benchmark dataset found at {kaggle_path} — finance forecasts will run on "
              f"your own data only (less accurate until you've logged a few months). "
              f"See README_ML.md to restore or replace it.")
        return None
    try:
        kdf = load_kaggle_dataset(kaggle_path)
        benchmark_shares = category_shares(kdf, "expense")
        print(f"Loaded benchmark dataset: {len(kdf)} rows, "
              f"{len(benchmark_shares)} expense categories, from {os.path.basename(kaggle_path)}.")
        return benchmark_shares
    except ValueError as e:
        print(f"Could not read the benchmark dataset: {e}")
        return None


def fetch_user_transactions(conn, user_id: int) -> pd.DataFrame:
    query = "SELECT txn_date AS date, category, amount, type FROM transactions WHERE user_id = %s ORDER BY txn_date"
    df = pd.read_sql(query, conn, params=(user_id,))
    if not df.empty:
        df["date"] = pd.to_datetime(df["date"])
    return df


def fetch_user_weekly_completion(conn, user_id: int) -> pd.Series:
    """
    Builds a week-by-week completion percentage:
    (check-ins that week) / (active goals that week * 7), oldest week first.
    Only counts the last 12 weeks so the trend reflects recent behaviour.
    """
    goals_df = pd.read_sql(
        "SELECT id, created_at FROM goals WHERE user_id = %s AND is_active = 1",
        conn, params=(user_id,)
    )
    if goals_df.empty:
        return pd.Series([], dtype=float)

    logs_df = pd.read_sql(
        """SELECT gl.log_date FROM goal_logs gl JOIN goals g ON g.id = gl.goal_id
           WHERE g.user_id = %s AND gl.status = 'done'
           AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 84 DAY)""",
        conn, params=(user_id,)
    )
    if logs_df.empty:
        return pd.Series([], dtype=float)

    logs_df["log_date"] = pd.to_datetime(logs_df["log_date"])
    logs_df["week"] = logs_df["log_date"].dt.to_period("W-SUN")
    counts = logs_df.groupby("week").size()

    n_goals = len(goals_df)
    weekly_pct = (counts / (n_goals * 7) * 100).clip(upper=100).sort_index()
    return weekly_pct


def build_finance_forecast(user_tx: pd.DataFrame, benchmark_shares) -> dict:
    if user_tx.empty:
        return {
            "status": "no_data",
            "message": "Add a few transactions to unlock your finance forecast.",
        }

    mt = monthly_totals(user_tx)
    if len(mt) == 0:
        return {"status": "no_data", "message": "Add a few transactions to unlock your finance forecast."}

    months_of_history = len(mt)
    income_preds, income_method, income_mae, income_rmse = forecast_series(mt["income"], FORECAST_MONTHS_AHEAD)
    expense_preds, expense_method, expense_mae, expense_rmse = forecast_series(mt["expense"], FORECAST_MONTHS_AHEAD)

    profit_actual = (mt["income"] - mt["expense"]).round(2)
    profit_preds = [round(i - e, 2) for i, e in zip(income_preds, expense_preds)]

    # Blend the user's own category split with the Kaggle benchmark split.
    # Trust grows toward "fully the user's own data" over 6 months of history.
    user_weight = min(1.0, months_of_history / 6.0)
    user_shares = category_shares(user_tx, "expense")
    if benchmark_shares:
        blended_shares = blend_category_shares(user_shares, benchmark_shares, user_weight)
        used_benchmark = True
    else:
        blended_shares = user_shares
        used_benchmark = False

    next_month_expense = expense_preds[0] if expense_preds else 0.0
    category_forecast = {
        cat: round(share * next_month_expense, 2)
        for cat, share in sorted(blended_shares.items(), key=lambda kv: -kv[1])
    }

    last_income = float(mt["income"].iloc[-1])
    last_expense = float(mt["expense"].iloc[-1])
    insights = []
    if expense_preds and last_expense > 0:
        pct_change = round((expense_preds[0] - last_expense) / last_expense * 100, 1)
        direction = "grow" if pct_change >= 0 else "shrink"
        insights.append(f"Your expenses are projected to {direction} {abs(pct_change)}% next month.")
    if profit_preds:
        trend = "growing" if profit_preds[-1] >= profit_preds[0] else "shrinking"
        insights.append(f"Projected savings are {trend} over the next {FORECAST_MONTHS_AHEAD} months.")
    if used_benchmark and user_weight < 1.0:
        insights.append(
            f"Category breakdown is still {round((1 - user_weight) * 100)}% based on typical spending "
            f"patterns from the benchmark dataset since you have {months_of_history} month(s) of your own "
            f"data — this will lean fully on your own history after 6 months."
        )

    return {
        "status": "ok",
        "months_of_history": months_of_history,
        "history": {
            "months": [str(m) for m in mt.index],
            "income": mt["income"].round(2).tolist(),
            "expense": mt["expense"].round(2).tolist(),
            "profit": profit_actual.tolist(),
        },
        "forecast": {
            "income": income_preds,
            "expense": expense_preds,
            "profit": profit_preds,
        },
        "model": {
            "income_method": income_method,
            "expense_method": expense_method,
            "income_mae": income_mae,
            "income_rmse": income_rmse,
            "expense_mae": expense_mae,
            "expense_rmse": expense_rmse,
        },
        "category_forecast_next_month": category_forecast,
        "used_kaggle_benchmark": used_benchmark,
        "insights": insights,
    }


def build_habit_forecast(weekly_pct: pd.Series) -> dict:
    if len(weekly_pct) < 2:
        return {
            "status": "no_data",
            "message": "Check in for at least two weeks to unlock your habit forecast.",
        }

    preds, method, mae, rmse = forecast_weekly_completion(weekly_pct, FORECAST_WEEKS_AHEAD)
    last_actual = float(weekly_pct.iloc[-1])
    insights = []
    if preds:
        change = round(preds[0] - last_actual, 1)
        if change > 3:
            insights.append(f"Your completion rate is trending up — projected +{change} pts next week.")
        elif change < -3:
            insights.append(f"Your completion rate is trending down — projected {change} pts next week. "
                             f"A couple of easy check-ins now can flatten that.")
        else:
            insights.append("Your completion rate is projected to stay roughly steady next week.")

    return {
        "status": "ok",
        "weeks_of_history": len(weekly_pct),
        "history": {
            "weeks": [str(w) for w in weekly_pct.index],
            "completion_pct": weekly_pct.round(1).tolist(),
        },
        "forecast": {
            "completion_pct": preds,
        },
        "model": {"method": method, "mae": mae, "rmse": rmse},
        "insights": insights,
    }


def save_forecast(conn, user_id: int, forecast_type: str, payload: dict):
    cur = conn.cursor()
    model_used = None
    mae = rmse = None
    if forecast_type == "finance" and payload.get("status") == "ok":
        model_used = payload["model"]["expense_method"]
        mae = payload["model"]["expense_mae"]
        rmse = payload["model"]["expense_rmse"]
    elif forecast_type == "habit" and payload.get("status") == "ok":
        model_used = payload["model"]["method"]
        mae = payload["model"]["mae"]
        rmse = payload["model"]["rmse"]

    cur.execute(
        """INSERT INTO forecast_cache (user_id, forecast_type, payload, model_used, mae, rmse, generated_at)
           VALUES (%s, %s, %s, %s, %s, %s, NOW())
           ON DUPLICATE KEY UPDATE payload=VALUES(payload), model_used=VALUES(model_used),
               mae=VALUES(mae), rmse=VALUES(rmse), generated_at=NOW()""",
        (user_id, forecast_type, json.dumps(payload), model_used, mae, rmse),
    )
    conn.commit()
    cur.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--kaggle", default=DEFAULT_KAGGLE_PATH,
                         help="Path to the benchmark dataset (.csv, .xlsx or .xls)")
    args = parser.parse_args()

    print(f"[{date.today()}] Starting forecast training run...")
    benchmark_shares = load_benchmark(args.kaggle)

    conn = connect_db()
    users_df = pd.read_sql("SELECT id, full_name FROM users", conn)
    if users_df.empty:
        print("No users found — register an account in the app first.")
        return

    for _, user in users_df.iterrows():
        uid, name = int(user["id"]), user["full_name"]
        print(f"\n--- {name} (user_id={uid}) ---")

        tx = fetch_user_transactions(conn, uid)
        finance_payload = build_finance_forecast(tx, benchmark_shares)
        save_forecast(conn, uid, "finance", finance_payload)
        print(f"  finance: {finance_payload.get('status')}")

        weekly = fetch_user_weekly_completion(conn, uid)
        habit_payload = build_habit_forecast(weekly)
        save_forecast(conn, uid, "habit", habit_payload)
        print(f"  habit:   {habit_payload.get('status')}")

    conn.close()
    print("\nDone. Refresh the Forecast page in the app to see the results.")


if __name__ == "__main__":
    main()
