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
     fills in for categories you haven't logged much of yourself yet, and
     it's shown on its own even before you've logged a single transaction
     (see build_finance_forecast's "benchmark_preview" state below).
  3. For every user in the app:
       - Pulls their own `transactions` rows, builds a monthly income/expense
         forecast blended with the benchmark, backtests two simple models
         (linear trend vs moving average) and keeps whichever predicted
         recent months more accurately.
       - Pulls their own weekly activity (`goal_logs` check-ins + each
         goal's self-reported `est_minutes`), builds a "Productivity Score"
         (a blend of completion rate and estimated time invested) plus a
         plain weekly completion-rate series, and forecasts both the same
         backtested way — no benchmark data involved here, since this is
         inherently personal behaviour. This is the "productivity and habit
         analysis engine" from the Milestone 2 brief.
     Both results are written as JSON into the `forecast_cache` table.
  4. finance.php / forecast.php simply SELECT the latest row and render it —
     no PHP page ever calls Python directly, so nothing in the live web app
     depends on Python being installed or running. The parts of the
     Productivity & Habits tab that don't need forecasting (habit-by-habit
     streaks, time-allocation-by-category, best/worst category) are plain
     PHP queries in includes/helpers.php, computed fresh on every page load
     — only the *trend prediction* piece needs this script to have been run.

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

import numpy as np
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
    forecast_linear,
    forecast_moving_average,
    forecast_arima,
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
DEFAULT_EST_MINUTES = 20  # matches the schema.sql column default


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


def fetch_weekly_activity(conn, user_id: int) -> pd.DataFrame:
    """
    Builds a week-by-week table (oldest first, last 12 weeks) with:
      - completion_pct: (check-ins that week) / (active goals * 7)
      - time_pct: (estimated minutes invested that week) / (target minutes
        for all active goals checking in every day that week)
      - productivity_score: 0.6 * completion_pct + 0.4 * time_pct
    Every active goal's own `est_minutes` (set on the Add Goal form, default
    20) is what turns raw check-in counts into an estimated time figure —
    this is deliberately a simple, explainable blend rather than a black-box
    score, in the same spirit as the finance model.
    """
    goals_df = pd.read_sql(
        "SELECT id, est_minutes FROM goals WHERE user_id = %s AND is_active = 1",
        conn, params=(user_id,)
    )
    if goals_df.empty:
        return pd.DataFrame(columns=["completion_pct", "time_pct", "productivity_score"])

    logs_df = pd.read_sql(
        """SELECT gl.goal_id, gl.log_date FROM goal_logs gl JOIN goals g ON g.id = gl.goal_id
           WHERE g.user_id = %s AND gl.status = 'done'
           AND gl.log_date >= DATE_SUB(CURDATE(), INTERVAL 84 DAY)""",
        conn, params=(user_id,)
    )
    if logs_df.empty:
        return pd.DataFrame(columns=["completion_pct", "time_pct", "productivity_score"])

    goals_df["est_minutes"] = goals_df["est_minutes"].fillna(DEFAULT_EST_MINUTES)
    minutes_map = dict(zip(goals_df["id"], goals_df["est_minutes"]))
    n_goals = len(goals_df)
    avg_minutes = float(goals_df["est_minutes"].mean())
    weekly_target_minutes = n_goals * avg_minutes * 7

    logs_df["log_date"] = pd.to_datetime(logs_df["log_date"])
    logs_df["week"] = logs_df["log_date"].dt.to_period("W-SUN")
    logs_df["minutes"] = logs_df["goal_id"].map(minutes_map).fillna(DEFAULT_EST_MINUTES)

    weekly = logs_df.groupby("week").agg(checkins=("goal_id", "count"), minutes=("minutes", "sum"))

    # Blend in real Focus Session minutes where we have them — a session
    # actually timed by the user is a better time-invested signal than the
    # goal's self-reported est_minutes, so for weeks with logged sessions we
    # use whichever is larger (a user who timed 40 real minutes on a goal
    # they estimated at 20 shouldn't be under-credited).
    focus_df = pd.read_sql(
        """SELECT started_at, actual_minutes FROM focus_sessions
           WHERE user_id = %s AND status IN ('completed','interrupted')
           AND started_at >= DATE_SUB(NOW(), INTERVAL 84 DAY)""",
        conn, params=(user_id,)
    )
    if not focus_df.empty:
        focus_df["started_at"] = pd.to_datetime(focus_df["started_at"])
        focus_df["week"] = focus_df["started_at"].dt.to_period("W-SUN")
        focus_weekly_minutes = focus_df.groupby("week")["actual_minutes"].sum()
        for wk, mins in focus_weekly_minutes.items():
            if wk in weekly.index:
                weekly.loc[wk, "minutes"] = max(weekly.loc[wk, "minutes"], float(mins))
            else:
                weekly.loc[wk, "minutes"] = float(mins)
                weekly.loc[wk, "checkins"] = weekly["checkins"].get(wk, 0)
        weekly = weekly.sort_index()
        weekly["checkins"] = weekly["checkins"].fillna(0)

    weekly["completion_pct"] = (weekly["checkins"] / (n_goals * 7) * 100).clip(upper=100)
    if weekly_target_minutes > 0:
        weekly["time_pct"] = (weekly["minutes"] / weekly_target_minutes * 100).clip(upper=100)
    else:
        weekly["time_pct"] = 0.0
    weekly["productivity_score"] = (0.6 * weekly["completion_pct"] + 0.4 * weekly["time_pct"])
    return weekly[["completion_pct", "time_pct", "productivity_score"]].sort_index()


def build_finance_forecast(user_tx: pd.DataFrame, benchmark_shares) -> dict:
    mt = monthly_totals(user_tx) if not user_tx.empty else pd.DataFrame()

    if user_tx.empty or len(mt) == 0:
        # No personal data yet. Rather than showing nothing (which made the
        # bundled dataset look like it wasn't "doing" anything), surface the
        # benchmark's own category breakdown as a clearly-labelled preview —
        # the actual forecast still needs at least one of your own months.
        if benchmark_shares:
            top = dict(sorted(benchmark_shares.items(), key=lambda kv: -kv[1])[:6])
            return {
                "status": "benchmark_preview",
                "message": "Add your first transaction on the Finance page to unlock your personal "
                           "forecast. Until then, here's what the benchmark dataset shows for a "
                           "typical spending breakdown.",
                "benchmark_category_shares": {k: round(v * 100, 1) for k, v in top.items()},
            }
        return {"status": "no_data", "message": "Add a few transactions to unlock your finance forecast."}

    months_of_history = len(mt)
    income_preds, income_method, income_mae, income_rmse, income_scores = forecast_series(mt["income"], FORECAST_MONTHS_AHEAD)
    expense_preds, expense_method, expense_mae, expense_rmse, expense_scores = forecast_series(mt["expense"], FORECAST_MONTHS_AHEAD)

    profit_actual = (mt["income"] - mt["expense"]).round(2)
    profit_preds = [round(i - e, 2) for i, e in zip(income_preds, expense_preds)]

    # Profit margin: profit as a % of income, per month (0 when there was no
    # income that month, rather than dividing by zero).
    profit_margin_actual = [
        round((p / i) * 100, 1) if i > 0 else 0.0
        for p, i in zip(profit_actual.tolist(), mt["income"].tolist())
    ]
    profit_margin_preds = [
        round((p / i) * 100, 1) if i > 0 else 0.0
        for p, i in zip(profit_preds, income_preds)
    ]

    # Cash flow: running cash position (cumulative profit) rather than a
    # per-month figure — this is what makes it a distinct metric from
    # "profit" on the Forecast page: profit is per-month, cash flow is the
    # running balance that profit feeds into.
    cash_flow_actual = np.cumsum(profit_actual.tolist()).round(2).tolist()
    running = cash_flow_actual[-1] if cash_flow_actual else 0.0
    cash_flow_preds = []
    for p in profit_preds:
        running = round(running + p, 2)
        cash_flow_preds.append(running)

    # Side-by-side next-month prediction from EACH of the three candidate
    # models (not just the winner) — lets the Forecast page show a real
    # "Linear Regression vs Moving Average vs ARIMA" comparison table for
    # Revenue/Expense/Profit/Profit Margin/Cash Flow, the same shape as a
    # typical financial-forecasting dashboard.
    last_cash_flow = cash_flow_actual[-1] if cash_flow_actual else 0.0
    model_fns = {
        "linear_trend": forecast_linear,
        "moving_average": forecast_moving_average,
        "arima": forecast_arima,
    }
    model_forecasts = {}
    for name, fn in model_fns.items():
        try:
            m_income = float(fn(mt["income"], 1)[0])
            m_expense = float(fn(mt["expense"], 1)[0])
        except Exception:
            m_income, m_expense = income_preds[0] if income_preds else 0.0, expense_preds[0] if expense_preds else 0.0
        m_profit = m_income - m_expense
        m_margin = round((m_profit / m_income) * 100, 1) if m_income > 0 else 0.0
        model_forecasts[name] = {
            "revenue": round(m_income, 2),
            "expense": round(m_expense, 2),
            "profit": round(m_profit, 2),
            "profit_margin": m_margin,
            "cash_flow": round(last_cash_flow + m_profit, 2),
        }

    # Blend the user's own category split with the benchmark dataset's split.
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
    last_margin = profit_margin_actual[-1] if profit_margin_actual else 0.0
    next_margin = profit_margin_preds[0] if profit_margin_preds else 0.0
    insights = []
    if expense_preds and last_expense > 0:
        pct_change = round((expense_preds[0] - last_expense) / last_expense * 100, 1)
        direction = "grow" if pct_change >= 0 else "shrink"
        insights.append(f"Your expenses are projected to {direction} {abs(pct_change)}% next month.")
    if profit_preds:
        trend = "growing" if profit_preds[-1] >= profit_preds[0] else "shrinking"
        insights.append(f"Projected savings are {trend} over the next {FORECAST_MONTHS_AHEAD} months.")
    if profit_margin_preds:
        margin_change = round(next_margin - last_margin, 1)
        if margin_change > 1:
            insights.append(f"Profit margin is projected to improve to {next_margin}% next month (from {last_margin}%).")
        elif margin_change < -1:
            insights.append(f"Profit margin is projected to slip to {next_margin}% next month (from {last_margin}%) — expenses are outpacing income growth.")
        else:
            insights.append(f"Profit margin is projected to hold steady around {next_margin}% next month.")
    if cash_flow_preds:
        cf_trend = "building up" if cash_flow_preds[-1] >= cash_flow_actual[-1] else "drawing down"
        insights.append(f"Your cumulative cash flow is {cf_trend}, projected to reach ₹{round(cash_flow_preds[-1]):,} by the end of the forecast window.")
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
            "profit_margin": profit_margin_actual,
            "cash_flow": cash_flow_actual,
        },
        "forecast": {
            "income": income_preds,
            "expense": expense_preds,
            "profit": profit_preds,
            "profit_margin": profit_margin_preds,
            "cash_flow": cash_flow_preds,
        },
        "model": {
            "income_method": income_method,
            "income_mae": income_mae,
            "income_rmse": income_rmse,
            "income_scores": income_scores,
            "expense_method": expense_method,
            "expense_mae": expense_mae,
            "expense_rmse": expense_rmse,
            "expense_scores": expense_scores,
        },
        "category_forecast_next_month": category_forecast,
        "model_forecasts_next_month": model_forecasts,
        "used_kaggle_benchmark": used_benchmark,
        "user_data_weight_pct": round(user_weight * 100),
        "months_of_history_used": months_of_history,
        "insights": insights,
    }


def build_habit_forecast(weekly: pd.DataFrame) -> dict:
    if len(weekly) < 2:
        return {
            "status": "no_data",
            "message": "Check in for at least two weeks to unlock your productivity & habit forecast.",
        }

    completion_preds, completion_method, completion_mae, completion_rmse, completion_scores = forecast_series(
        weekly["completion_pct"], FORECAST_WEEKS_AHEAD
    )
    completion_preds = [max(0.0, min(100.0, p)) for p in completion_preds]

    prod_preds, prod_method, prod_mae, prod_rmse, prod_scores = forecast_series(
        weekly["productivity_score"], FORECAST_WEEKS_AHEAD
    )
    prod_preds = [max(0.0, min(100.0, p)) for p in prod_preds]

    last_completion = float(weekly["completion_pct"].iloc[-1])
    last_prod = float(weekly["productivity_score"].iloc[-1])

    insights = []
    if completion_preds:
        change = round(completion_preds[0] - last_completion, 1)
        if change > 3:
            insights.append(f"Your completion rate is trending up — projected +{change} pts next week.")
        elif change < -3:
            insights.append(f"Your completion rate is trending down — projected {change} pts next week. "
                             f"A couple of easy check-ins now can flatten that.")
        else:
            insights.append("Your completion rate is projected to stay roughly steady next week.")
    if prod_preds:
        change2 = round(prod_preds[0] - last_prod, 1)
        direction = "climb" if change2 >= 0 else "dip"
        insights.append(
            f"Productivity Score — a blend of completion rate and estimated time invested — is "
            f"projected to {direction} to {round(prod_preds[0])}/100 next week."
        )

    return {
        "status": "ok",
        "weeks_of_history": len(weekly),
        "history": {
            "weeks": [str(w) for w in weekly.index],
            "completion_pct": weekly["completion_pct"].round(1).tolist(),
            "productivity_score": weekly["productivity_score"].round(1).tolist(),
        },
        "forecast": {
            "completion_pct": completion_preds,
            "productivity_score": prod_preds,
        },
        "model": {
            "completion_method": completion_method, "completion_mae": completion_mae, "completion_rmse": completion_rmse,
            "completion_scores": completion_scores,
            "productivity_method": prod_method, "productivity_mae": prod_mae, "productivity_rmse": prod_rmse,
            "productivity_scores": prod_scores,
        },
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
        model_used = payload["model"]["productivity_method"]
        mae = payload["model"]["productivity_mae"]
        rmse = payload["model"]["productivity_rmse"]

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

        weekly = fetch_weekly_activity(conn, uid)
        habit_payload = build_habit_forecast(weekly)
        save_forecast(conn, uid, "habit", habit_payload)
        print(f"  habit:   {habit_payload.get('status')}")

    conn.close()
    print("\nDone. Refresh the Forecast page in the app to see the results.")


if __name__ == "__main__":
    main()
