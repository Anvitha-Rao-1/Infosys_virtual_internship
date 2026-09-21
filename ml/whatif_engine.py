"""
whatif_engine.py
-----------------
Engine D — What-If Analysis & Scenario Simulation.

This engine sits ON TOP of the existing ML system. It does not train a
model, it does not replace a model, and it does not download a dataset.
It is deliberately a thin layer whose entire job is:

    take the EXISTING model, feed it a MODIFIED input, record what it said.

Concretely it reuses, by direct import, the exact functions the existing
forecasting pipeline already uses:

  * ml/forecasting.py  — monthly_totals(), forecast_linear(),
                          forecast_arima(), forecast_xgboost(),
                          forecast_series(), confidence_interval()
  * ml/train_model.py  — fetch_user_transactions(), fetch_weekly_activity(),
                          DB_CONFIG, FORECAST_MONTHS_AHEAD/WEEKS_AHEAD
  * ml/data/external/burnout_model.joblib — the ALREADY-TRAINED
                          RandomForestClassifier from Engine C, loaded and
                          called with .predict_proba() on modified feature
                          vectors. It is never re-fitted here.

Nothing in forecasting.py, train_model.py or burnout_model.py is modified.
Importing train_model is safe: everything there is behind functions or a
`if __name__ == "__main__"` guard, so importing it runs no training.

--------------------------------------------------------------------------
HOW A SCENARIO IS PRODUCED (the honest version)
--------------------------------------------------------------------------
For each category we pick ONE lever the user can actually move, then:

  1. BASELINE. Run the existing forecast_series() on the user's real,
     unmodified history. This returns the winning model (chosen by the
     same backtest the app already uses), its MAE/RMSE, and its forecast.
     This baseline is identical to what analyse.php already shows.

  2. LOCK THE MODEL. Every scenario is then forecast with the SAME winning
     method as the baseline, rather than re-running the model-selection
     backtest per scenario. This is deliberate: if scenario A were forecast
     by ARIMA and scenario B by XGBoost, part of the difference between
     them would be a model artifact rather than an effect of the lever.
     Holding the model fixed makes the comparison a fair one.

  3. MODIFY THE INPUT. The lever is applied as a multiplicative rescale of
     the historical input series (see each build_* function for the exact
     factor and the assumption it encodes). The SHAPE and TREND of the
     user's history are preserved — which is what the models actually learn
     from — and only the LEVEL moves. This is why the lever is described in
     the UI as "what if your recent history had run at this level instead".

  4. RE-PREDICT. The modified series goes through the same forecast
     function. Because ARIMA and XGBoost are non-linear, the output is NOT
     simply the baseline times the lever factor.

  5. RECORD. Every grid point's prediction is stored, so the PHP UI can
     render a slider, a comparison and a response curve with zero runtime
     Python.

The result is written to the `whatif_cache` table (see
database/migration_whatif.sql — an ADDITIVE migration). simulate.php reads
that table. No PHP page ever calls Python, exactly like Engine A.

--------------------------------------------------------------------------
Usage:
    python whatif_engine.py                # build grids for every user
    python whatif_engine.py --user 9       # just one user
    python whatif_engine.py --dry-run      # compute + print, write nothing

Requires: pip install -r requirements.txt (no new packages).
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import warnings
from datetime import datetime

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore", message="pandas only supports SQLAlchemy")

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

# --- existing pipeline, imported and reused as-is -------------------------
from forecasting import (
    monthly_totals,
    forecast_series,
    forecast_linear,
    forecast_arima,
    forecast_xgboost,
    confidence_interval,
)
from train_model import (
    DB_CONFIG,
    FORECAST_MONTHS_AHEAD,
    FORECAST_WEEKS_AHEAD,
    DEFAULT_EST_MINUTES,
    fetch_user_transactions,
    fetch_weekly_activity,
)

try:
    import mysql.connector
except ImportError:
    print("Missing dependency. Run:  pip install -r requirements.txt")
    sys.exit(1)

try:
    import joblib
    _HAS_JOBLIB = True
except ImportError:
    _HAS_JOBLIB = False

BURNOUT_MODEL_PATH = os.path.join(HERE, "data", "external", "burnout_model.joblib")

# The Productivity Score weights, restated from train_model.fetch_weekly_activity
# so a scenario's score is computed by the SAME formula the real score uses.
# (They are constants in that function rather than module-level names, so they
# cannot be imported; if you ever change them there, change them here too.)
PRODUCTIVITY_COMPLETION_WEIGHT = 0.6
PRODUCTIVITY_TIME_WEIGHT = 0.4


# ---------------------------------------------------------------------------
# Small shared helpers
# ---------------------------------------------------------------------------

def connect_db():
    try:
        return mysql.connector.connect(**DB_CONFIG)
    except mysql.connector.Error as e:
        print(f"Could not connect to MySQL: {e}")
        print("Make sure XAMPP's MySQL is running and the habit_tracker database exists.")
        sys.exit(1)


def forecast_with(method: str, series: pd.Series, periods: int) -> np.ndarray:
    """Forecast with one SPECIFIC existing method (no model re-selection).

    Used for every scenario so that all scenarios in a comparison are
    produced by the same model as the baseline — see step 2 in the module
    docstring for why that matters.
    """
    if method == "arima":
        return forecast_arima(series, periods)
    if method == "xgboost":
        return forecast_xgboost(series, periods)
    return forecast_linear(series, periods)


def build_grid_values(lo: float, hi: float, step: float) -> list:
    """Inclusive numeric grid, rounded to avoid 0.30000000000000004 labels."""
    n = int(round((hi - lo) / step)) + 1
    return [round(lo + i * step, 4) for i in range(n)]


def snap(value: float, grid: list) -> float:
    """Nearest grid point to a raw value — the slider can only sit on grid
    points, because those are the only inputs the model was actually run on.
    We never interpolate a prediction the model did not make."""
    return min(grid, key=lambda g: abs(g - value))


def pct_change(new: float, old: float):
    if old is None or abs(old) < 1e-9:
        return None
    return round((new - old) / abs(old) * 100, 1)


def pick_scenarios(grid: list, current: float, improve_by: float, risk_by: float) -> dict:
    """The EXPECTED / IMPROVED / RISK triplet, as three points on the grid.

    EXPECTED is the grid point closest to what the user is actually doing
    now — i.e. "carry on as you are". IMPROVED and RISK are a fixed step
    up and down from there, clamped to the ends of the grid. They are
    deliberately NOT the best and worst points on the grid: a scenario is
    only useful if it is a plausible change, not a fantasy.
    """
    expected = snap(current, grid)
    improved = snap(min(max(grid), expected + improve_by), grid)
    risk = snap(max(min(grid), expected - risk_by), grid)
    return {"expected": expected, "improved": improved, "risk": risk}


def response_summary(grid: list, direction: str, abs_threshold: float) -> dict:
    """
    Describes how much the prediction ACTUALLY moves across the whole lever
    range, and whether it moves monotonically.

    This exists because a scenario comparison can be genuinely uninformative
    and the UI should say so rather than draw three near-identical bars and
    imply a difference that isn't there. Two cases it catches:

      * FLAT — the model's prediction barely responds to the lever. That is
        a real result (often "something else dominates your outcome"), not a
        bug, and it is worth stating plainly.
      * NON-MONOTONIC — the response wobbles instead of rising or falling
        steadily. Tree models especially do this, because they predict in
        steps rather than along a smooth curve. Worth flagging so nobody
        reads a small wobble as a meaningful effect.
    """
    values = [g["headline"] for g in grid]
    if not values:
        return {"flat": True, "monotonic": True, "range": 0.0, "min": None, "max": None}
    lo, hi = min(values), max(values)
    rng = hi - lo
    scale = max(abs(hi), abs(lo), 1e-9)

    better_is_up = direction == "higher_is_better"
    ordered = values if better_is_up else list(reversed(values))
    monotonic = all(b >= a - abs_threshold * 0.5 for a, b in zip(ordered, ordered[1:]))

    return {
        "flat": rng < abs_threshold or (rng / scale) < 0.02,
        "monotonic": bool(monotonic),
        "range": round(rng, 2),
        "min": round(lo, 2),
        "max": round(hi, 2),
    }


# ---------------------------------------------------------------------------
# FINANCE — lever: savings rate (% of income not spent)
# ---------------------------------------------------------------------------

# Runs to 70% rather than 50% because a student living at home can genuinely
# sit above a 50% savings rate, and a slider whose maximum is below where the
# user already is leaves them no "improved" scenario at all.
SAVINGS_GRID = build_grid_values(0, 70, 5)


def build_finance_whatif(conn, uid: int) -> dict:
    """
    Lever: SAVINGS RATE — the share of income the user does not spend.

    How the lever reaches the model:
      savings_rate r  =>  expenses must equal income * (1 - r).
      The user's real history already implies a savings rate r_now. So we
      rescale the whole historical EXPENSE series by

          f = (1 - r) / (1 - r_now)

      and push that counterfactual expense history through the existing
      forecast function. The INCOME series is never modified — saving more
      is a spending decision, not an earning one — so the income forecast is
      the baseline one in every scenario.

    Assumption being encoded (stated in the UI, not hidden): the user keeps
    spending on the same things in the same proportions and with the same
    month-to-month pattern, just scaled up or down. That is what lets the
    existing model, which learned that pattern, still apply.
    """
    tx = fetch_user_transactions(conn, uid)
    if tx.empty:
        return {"status": "no_data",
                "message": "Log some income and expense transactions on the Finance page to unlock the finance simulator."}

    mt = monthly_totals(tx)
    if len(mt) < 2:
        return {"status": "no_data",
                "message": "Needs at least two completed months of transactions. Keep logging — the simulator unlocks automatically."}

    income = mt["income"]
    expense = mt["expense"]

    total_income = float(income.sum())
    if total_income <= 0:
        return {"status": "no_data",
                "message": "The savings-rate simulator needs some logged income to work from — every scenario is a share of what you earn."}

    # Current savings rate: the last 3 completed months (recent behaviour).
    # Historical average: the whole logged history (the long-run habit).
    recent = mt.tail(3)
    recent_income = float(recent["income"].sum())
    current_rate = ((recent_income - float(recent["expense"].sum())) / recent_income * 100) if recent_income > 0 else 0.0
    historical_rate = (total_income - float(expense.sum())) / total_income * 100

    current_rate = float(np.clip(current_rate, 0, max(SAVINGS_GRID)))
    historical_rate = float(np.clip(historical_rate, -200, 100))

    # --- 1. BASELINE: the existing model on the real, unmodified data ------
    inc_preds, inc_method, inc_mae, inc_rmse, inc_scores, inc_lo, inc_hi = forecast_series(
        income, FORECAST_MONTHS_AHEAD)
    exp_preds, exp_method, exp_mae, exp_rmse, exp_scores, exp_lo, exp_hi = forecast_series(
        expense, FORECAST_MONTHS_AHEAD)

    baseline_profit = [round(i - e, 2) for i, e in zip(inc_preds, exp_preds)]

    # The savings rate the baseline forecast itself implies, which is not
    # necessarily today's rate — the model may be projecting a drift.
    baseline_rate = (
        round((inc_preds[0] - exp_preds[0]) / inc_preds[0] * 100, 1)
        if inc_preds and inc_preds[0] > 0 else None
    )

    # r_now used for the rescale is taken from the RECENT months, i.e. the
    # same period whose level the lever is re-stating.
    r_now = current_rate / 100.0
    if r_now >= 0.95:  # degenerate — can't scale a near-zero expense base
        r_now = 0.95

    history_profit = (income - expense).round(2).tolist()

    # --- 2-5. every grid point through the SAME (baseline) model -----------
    grid = []
    for r_pct in SAVINGS_GRID:
        r = r_pct / 100.0
        f = (1 - r) / (1 - r_now)
        scaled_expense = (expense * f).clip(lower=0)

        scen_exp = forecast_with(exp_method, scaled_expense, FORECAST_MONTHS_AHEAD)
        scen_exp = [round(float(v), 2) for v in scen_exp]
        scen_profit = [round(i - e, 2) for i, e in zip(inc_preds, scen_exp)]

        # The expense model's own backtest error, rescaled by the same factor
        # the series was rescaled by, so the confidence band stays honest
        # rather than being copied across unchanged.
        scen_rmse = None if exp_rmse is None else exp_rmse * f
        p_lo, p_hi = confidence_interval(scen_profit, scen_rmse)

        grid.append({
            "value": r_pct,
            "headline": scen_profit[0] if scen_profit else 0.0,       # next month's savings
            "cumulative": round(float(np.sum(scen_profit)), 2),        # over the forecast window
            "expense_next_month": scen_exp[0] if scen_exp else 0.0,
            "income_next_month": inc_preds[0] if inc_preds else 0.0,
            "forecast": scen_profit,
            "forecast_lower": p_lo,
            "forecast_upper": p_hi,
            "scale_factor": round(f, 4),
        })

    scen_values = pick_scenarios(SAVINGS_GRID, current_rate, improve_by=10, risk_by=10)
    by_value = {g["value"]: g for g in grid}

    return {
        "status": "ok",
        "category": "finance",
        "lever": {
            "key": "savings_rate",
            "label": "Savings rate",
            "description": "The share of your income you don't spend.",
            "unit": "%",
            "min": min(SAVINGS_GRID), "max": max(SAVINGS_GRID),
            "step": 5,
            "grid_values": SAVINGS_GRID,
        },
        "metric": {
            "key": "monthly_savings",
            "label": "Projected savings next month",
            "unit": "₹",
            "prefix": "₹", "suffix": "",
            "direction": "higher_is_better",
        },
        "current_value": round(current_rate, 1),
        "historical_average": round(historical_rate, 1),
        "baseline": {
            "headline": baseline_profit[0] if baseline_profit else 0.0,
            "forecast": baseline_profit,
            "implied_lever_value": baseline_rate,
            "income_forecast": inc_preds,
            "expense_forecast": exp_preds,
        },
        "history": {
            "labels": [str(m) for m in mt.index],
            "values": history_profit,
            "income": income.round(2).tolist(),
            "expense": expense.round(2).tolist(),
        },
        "grid": grid,
        "response": response_summary(grid, "higher_is_better", abs_threshold=100.0),
        "scenario_values": scen_values,
        "scenarios": {k: by_value[v] for k, v in scen_values.items()},
        "model": {
            "method": exp_method,
            "mae": exp_mae, "rmse": exp_rmse,
            "accuracy": (exp_scores.get(exp_method) or {}).get("accuracy"),
            "income_method": inc_method,
            "all_scores": exp_scores,
        },
        "assumptions": [
            "Your income forecast is unchanged in every scenario — saving more is modelled as a spending decision, not an earning one.",
            "A scenario rescales your whole logged expense history so that expenses equal income x (1 - savings rate), then re-forecasts it. Your month-to-month spending pattern and trend are preserved; only the level moves.",
            f"Every scenario is forecast with the same model the baseline picked ({exp_method}), so the differences you see come from the lever, not from switching models.",
            "This projects the next {} months. It is a projection from your own logged history, not a guarantee.".format(FORECAST_MONTHS_AHEAD),
        ],
        "months_of_history": len(mt),
        "generated_at": datetime.now().isoformat(),
    }


# ---------------------------------------------------------------------------
# HABITS — lever: check-ins per week
# ---------------------------------------------------------------------------

HABIT_GRID = build_grid_values(0, 7, 0.5)


def build_habits_whatif(conn, uid: int) -> dict:
    """
    Lever: CHECK-IN DAYS PER WEEK.

    The existing habit model forecasts `completion_pct`, which is defined by
    train_model.fetch_weekly_activity as check-ins / (active goals x 7) x 100.
    So "days per week" and "completion %" are the same quantity on two
    scales: days = completion_pct / 100 * 7. The lever therefore maps onto a
    real model input exactly, with no invented variable.

    A scenario rescales the historical completion series by
        f = target_days / current_days
    and re-forecasts it with the baseline's winning model. The Productivity
    Score series is rescaled by the same factor, because its time component
    (minutes invested) is itself driven by how often you check in.
    """
    weekly = fetch_weekly_activity(conn, uid)
    if len(weekly) < 2:
        return {"status": "no_data",
                "message": "Check in on your habits for at least two full weeks to unlock the habit simulator."}

    completion = weekly["completion_pct"]
    productivity = weekly["productivity_score"]

    recent = completion.tail(4)
    current_days = float(recent.mean()) / 100.0 * 7.0
    historical_days = float(completion.mean()) / 100.0 * 7.0
    current_days = float(np.clip(current_days, 0, 7))

    # --- BASELINE (identical to what analyse.php already shows) ------------
    c_preds, c_method, c_mae, c_rmse, c_scores, c_lo, c_hi = forecast_series(
        completion, FORECAST_WEEKS_AHEAD)
    p_preds, p_method, p_mae, p_rmse, p_scores, p_lo, p_hi = forecast_series(
        productivity, FORECAST_WEEKS_AHEAD)
    c_preds = [max(0.0, min(100.0, v)) for v in c_preds]
    p_preds = [max(0.0, min(100.0, v)) for v in p_preds]

    if current_days <= 0.05:
        # Everything below divides by current_days; with essentially no
        # check-ins there is no "current level" to rescale from.
        return {"status": "no_data",
                "message": "You have logged almost no check-ins in the last four weeks, so there's no current level for the simulator to scale from. Check in for a few days and it will unlock."}

    grid = []
    for days in HABIT_GRID:
        f = days / current_days
        scaled_completion = (completion * f).clip(upper=100, lower=0)
        scaled_productivity = (productivity * f).clip(upper=100, lower=0)

        sc = forecast_with(c_method, scaled_completion, FORECAST_WEEKS_AHEAD)
        sc = [round(max(0.0, min(100.0, float(v))), 2) for v in sc]
        sp = forecast_with(p_method, scaled_productivity, FORECAST_WEEKS_AHEAD)
        sp = [round(max(0.0, min(100.0, float(v))), 2) for v in sp]

        scen_rmse = None if c_rmse is None else c_rmse * f
        lo, hi = confidence_interval(sc, scen_rmse)
        lo = [None if v is None else max(0.0, min(100.0, v)) for v in lo]
        hi = [None if v is None else max(0.0, min(100.0, v)) for v in hi]

        grid.append({
            "value": days,
            "headline": sc[0] if sc else 0.0,                 # completion % next week
            "productivity": sp[0] if sp else 0.0,
            "forecast": sc,
            "productivity_forecast": sp,
            "forecast_lower": lo,
            "forecast_upper": hi,
            "scale_factor": round(f, 4),
        })

    scen_values = pick_scenarios(HABIT_GRID, current_days, improve_by=2.0, risk_by=1.5)
    by_value = {g["value"]: g for g in grid}

    payload = {
        "status": "ok",
        "category": "habits",
        "lever": {
            "key": "checkin_days",
            "label": "Habit check-ins per week",
            "description": "How many days a week you complete your active habits.",
            "unit": " days/wk",
            "min": min(HABIT_GRID), "max": max(HABIT_GRID),
            "step": 0.5,
            "grid_values": HABIT_GRID,
        },
        "metric": {
            "key": "completion_pct",
            "label": "Projected completion rate next week",
            "unit": "%",
            "prefix": "", "suffix": "%",
            "direction": "higher_is_better",
        },
        "current_value": round(current_days, 2),
        "historical_average": round(historical_days, 2),
        "baseline": {
            "headline": round(c_preds[0], 2) if c_preds else 0.0,
            "forecast": [round(v, 2) for v in c_preds],
            "productivity_forecast": [round(v, 2) for v in p_preds],
            "implied_lever_value": round(c_preds[0] / 100 * 7, 2) if c_preds else None,
        },
        "history": {
            "labels": [f"Wk {i + 1}" for i in range(len(completion))],
            "values": completion.round(1).tolist(),
            "productivity": productivity.round(1).tolist(),
        },
        "grid": grid,
        "response": response_summary(grid, "higher_is_better", abs_threshold=1.0),
        "scenario_values": scen_values,
        "scenarios": {k: by_value[v] for k, v in scen_values.items()},
        "model": {
            "method": c_method,
            "mae": c_mae, "rmse": c_rmse,
            "accuracy": (c_scores.get(c_method) or {}).get("accuracy"),
            "productivity_method": p_method,
            "all_scores": c_scores,
        },
        "assumptions": [
            "Check-in days and completion rate are the same measurement on two scales (days = completion % / 100 x 7), so the lever maps directly onto a real model input.",
            "A scenario rescales your logged weekly completion history to the chosen level and re-forecasts it. Your week-to-week pattern is preserved; only the level moves.",
            "Your Productivity Score is rescaled by the same factor, because the minutes it counts are driven by how often you check in.",
            f"Every scenario uses the same model the baseline picked ({c_method}), so differences come from the lever rather than from switching models.",
        ],
        "weeks_of_history": len(weekly),
        "generated_at": datetime.now().isoformat(),
    }

    burnout = build_burnout_whatif(conn, uid)
    if burnout:
        payload["burnout_simulation"] = burnout
    return payload


# ---------------------------------------------------------------------------
# HABITS (secondary) — the already-trained burnout classifier, re-scored
# ---------------------------------------------------------------------------

SLEEP_GRID = build_grid_values(4.0, 10.0, 0.5)


def build_burnout_whatif(conn, uid: int):
    """
    Lever: SLEEP HOURS (with an exercise on/off comparison).

    This is the purest "existing model as a black box" case in the whole
    project. It:
      1. loads ml/data/external/burnout_model.joblib — the RandomForest
         Engine C already trained on the Kaggle teen-mental-health dataset,
      2. reads the feature vector Engine C already computed for this user
         and stored in burnout_predictions.payload['features_used'],
      3. changes ONE value in that vector,
      4. calls .predict_proba() on it.

    No re-fitting, no re-derivation of the features, and burnout_model.py is
    not imported or modified — we read its own stored output. If either the
    model file or the user's stored prediction is missing, this block is
    simply omitted and the rest of the habit simulator still works.
    """
    if not _HAS_JOBLIB or not os.path.exists(BURNOUT_MODEL_PATH):
        return None

    cur = conn.cursor(dictionary=True)
    cur.execute("SELECT payload FROM burnout_predictions WHERE user_id=%s", (uid,))
    row = cur.fetchone()
    cur.close()
    if not row:
        return None

    try:
        stored = json.loads(row["payload"])
        features = stored["features_used"]
        bundle = joblib.load(BURNOUT_MODEL_PATH)
        clf = bundle["model"]
        feature_order = list(features.keys())
    except Exception as e:
        print(f"    [burnout what-if] skipped: {e}")
        return None

    # The model's own column order — taken from the estimator where possible
    # so a mismatch can never silently reorder the features.
    if hasattr(clf, "feature_names_in_"):
        feature_order = list(clf.feature_names_in_)
    missing = [f for f in feature_order if f not in features]
    if missing:
        print(f"    [burnout what-if] skipped: stored features missing {missing}")
        return None

    current_sleep = float(features.get("sleep_hours", 7.0))
    baseline_risk = float(stored.get("risk_score", 0.0))

    def score(sleep_hours: float, exercised: float) -> float:
        f = dict(features)
        f["sleep_hours"] = sleep_hours
        f["exercised_today"] = exercised
        X = pd.DataFrame([f])[feature_order]
        return round(float(clf.predict_proba(X)[0, 1]) * 100, 1)

    curves = {}
    for label, ex in [("as_logged", features.get("exercised_today", 0)),
                      ("with_exercise", 1), ("without_exercise", 0)]:
        curves[label] = [score(s, ex) for s in SLEEP_GRID]

    scen_values = pick_scenarios(SLEEP_GRID, current_sleep, improve_by=1.5, risk_by=1.5)
    as_logged_ex = features.get("exercised_today", 0)

    metrics = bundle.get("metrics", {})
    pseudo_grid = [{"headline": v} for v in curves["as_logged"]]

    return {
        "status": "ok",
        "lever": {
            "key": "sleep_hours",
            "label": "Average sleep",
            "description": "Your average nightly sleep over the days you logged a mood entry.",
            "unit": "h",
            "min": min(SLEEP_GRID), "max": max(SLEEP_GRID),
            "step": 0.5,
            "grid_values": SLEEP_GRID,
        },
        "metric": {
            "key": "burnout_risk",
            "label": "Predicted burnout risk",
            "unit": "%", "prefix": "", "suffix": "%",
            "direction": "lower_is_better",
        },
        "current_value": round(current_sleep, 1),
        "baseline": {"headline": round(baseline_risk * 100, 1)},
        "sleep_grid": SLEEP_GRID,
        "curves": curves,
        "response": response_summary(pseudo_grid, "lower_is_better", abs_threshold=2.0),
        "exercised_as_logged": int(as_logged_ex) if float(as_logged_ex) in (0.0, 1.0) else round(float(as_logged_ex), 2),
        "scenario_values": scen_values,
        "scenarios": {
            k: {"value": v, "headline": score(v, as_logged_ex)}
            for k, v in scen_values.items()
        },
        "model": {
            "method": "RandomForestClassifier",
            "accuracy": round(metrics["accuracy"] * 100, 1) if metrics.get("accuracy") is not None else None,
            "roc_auc": round(metrics["roc_auc"], 3) if metrics.get("roc_auc") is not None else None,
            "trained_at": bundle.get("trained_at"),
        },
        "data_completeness_pct": stored.get("data_completeness_pct"),
        "assumptions": [
            "This re-scores the burnout classifier that was already trained on the Kaggle teen-mental-health dataset. Nothing is retrained here — one value in your feature vector is changed and the model is asked again.",
            "Only sleep (and the exercise on/off comparison) is moved. Every other feature is held at the value Engine C computed for you.",
            "Features this app doesn't collect (screen time, social interaction, felt support) are filled from the benchmark population average, so they are the same in every scenario and cannot be what causes the difference.",
            "The model shows an association learned from a population, not a causal guarantee about you.",
        ],
        "generated_at": datetime.now().isoformat(),
    }


# ---------------------------------------------------------------------------
# PRODUCTIVITY — lever: focused hours per day
# ---------------------------------------------------------------------------

FOCUS_GRID = build_grid_values(0.5, 6.0, 0.5)


def weekly_target_minutes(conn, uid: int):
    """The same denominator train_model.fetch_weekly_activity uses to turn
    minutes into time_pct: active goals x average est_minutes x 7. Recomputed
    here (rather than imported) because that function keeps it as a local
    variable and returns only the derived percentages."""
    df = pd.read_sql(
        "SELECT est_minutes FROM goals WHERE user_id = %s AND is_active = 1",
        conn, params=(uid,)
    )
    if df.empty:
        return None
    est = df["est_minutes"].fillna(DEFAULT_EST_MINUTES)
    return float(len(df)) * float(est.mean()) * 7.0


def build_productivity_whatif(conn, uid: int) -> dict:
    """
    Lever: FOCUSED HOURS PER DAY.

    The existing Productivity Score is defined in
    train_model.fetch_weekly_activity as

        productivity_score = 0.6 x completion_pct + 0.4 x time_pct
        time_pct           = minutes invested / weekly target minutes x 100

    The lever moves the TIME half of that, leaving completion alone: this is
    the "I studied/worked longer, not more often" scenario, which is a
    genuinely different question from the habits simulator's "I showed up on
    more days". A scenario rebuilds the productivity series with the SAME
    formula the real score uses, then forecasts it with the baseline model.
    """
    weekly = fetch_weekly_activity(conn, uid)
    if len(weekly) < 2:
        return {"status": "no_data",
                "message": "Log at least two full weeks of check-ins or focus sessions to unlock the productivity simulator."}

    target_minutes = weekly_target_minutes(conn, uid)
    if not target_minutes or target_minutes <= 0:
        return {"status": "no_data",
                "message": "Add an active goal with an estimated time per check-in — the productivity simulator needs it to convert hours into a score."}

    completion = weekly["completion_pct"]
    time_pct = weekly["time_pct"]
    productivity = weekly["productivity_score"]

    # time_pct -> real minutes -> hours/day, using the same denominator the
    # score itself uses, so the lever is in the score's own units.
    hours_per_day = (time_pct / 100.0 * target_minutes) / 7.0 / 60.0
    current_hours = float(hours_per_day.tail(4).mean())
    historical_hours = float(hours_per_day.mean())

    if current_hours <= 0.02:
        return {"status": "no_data",
                "message": "No focused time logged in the last few weeks, so there's no current level to scale from. Run a Focus Session or check in on a goal and this will unlock."}

    # --- BASELINE ----------------------------------------------------------
    p_preds, p_method, p_mae, p_rmse, p_scores, p_lo, p_hi = forecast_series(
        productivity, FORECAST_WEEKS_AHEAD)
    p_preds = [max(0.0, min(100.0, v)) for v in p_preds]

    grid = []
    for hours in FOCUS_GRID:
        f = hours / current_hours
        scaled_time_pct = (time_pct * f).clip(upper=100, lower=0)
        # Rebuilt with the score's OWN formula — not an approximation of it.
        scaled_productivity = (
            PRODUCTIVITY_COMPLETION_WEIGHT * completion
            + PRODUCTIVITY_TIME_WEIGHT * scaled_time_pct
        ).clip(upper=100, lower=0)

        sp = forecast_with(p_method, scaled_productivity, FORECAST_WEEKS_AHEAD)
        sp = [round(max(0.0, min(100.0, float(v))), 2) for v in sp]

        # The productivity series only moves by its 0.4-weighted time half,
        # so the error scales by that share of the rescale, not the whole of it.
        blend = PRODUCTIVITY_COMPLETION_WEIGHT + PRODUCTIVITY_TIME_WEIGHT * f
        scen_rmse = None if p_rmse is None else p_rmse * blend
        lo, hi = confidence_interval(sp, scen_rmse)
        lo = [None if v is None else max(0.0, min(100.0, v)) for v in lo]
        hi = [None if v is None else max(0.0, min(100.0, v)) for v in hi]

        grid.append({
            "value": hours,
            "headline": sp[0] if sp else 0.0,
            "weekly_hours": round(hours * 7, 1),
            "forecast": sp,
            "forecast_lower": lo,
            "forecast_upper": hi,
            "scale_factor": round(f, 4),
        })

    scen_values = pick_scenarios(FOCUS_GRID, current_hours, improve_by=1.0, risk_by=1.5)
    by_value = {g["value"]: g for g in grid}

    return {
        "status": "ok",
        "category": "productivity",
        "lever": {
            "key": "focus_hours",
            "label": "Focused hours per day",
            "description": "Time actually invested per day — Focus Session minutes where you timed them, otherwise your per-goal estimate.",
            "unit": "h/day",
            "min": min(FOCUS_GRID), "max": max(FOCUS_GRID),
            "step": 0.5,
            "grid_values": FOCUS_GRID,
        },
        "metric": {
            "key": "productivity_score",
            "label": "Projected Productivity Score next week",
            "unit": "/100",
            "prefix": "", "suffix": "/100",
            "direction": "higher_is_better",
        },
        "current_value": round(current_hours, 2),
        "historical_average": round(historical_hours, 2),
        "baseline": {
            "headline": round(p_preds[0], 2) if p_preds else 0.0,
            "forecast": [round(v, 2) for v in p_preds],
            "implied_lever_value": round(current_hours, 2),
        },
        "history": {
            "labels": [f"Wk {i + 1}" for i in range(len(productivity))],
            "values": productivity.round(1).tolist(),
            "hours_per_day": hours_per_day.round(2).tolist(),
            "completion": completion.round(1).tolist(),
        },
        "grid": grid,
        "response": response_summary(grid, "higher_is_better", abs_threshold=1.0),
        "scenario_values": scen_values,
        "scenarios": {k: by_value[v] for k, v in scen_values.items()},
        "model": {
            "method": p_method,
            "mae": p_mae, "rmse": p_rmse,
            "accuracy": (p_scores.get(p_method) or {}).get("accuracy"),
            "all_scores": p_scores,
        },
        "assumptions": [
            "Only the time half of the Productivity Score moves. How OFTEN you check in is held at your real history — that is the habits simulator's lever, not this one.",
            "Scenario scores are rebuilt with the score's own formula (0.6 x completion + 0.4 x time), then forecast — the formula is not re-derived or approximated here.",
            "Hours are converted using the same weekly target (active goals x estimated minutes x 7) the real score uses.",
            f"Every scenario uses the same model the baseline picked ({p_method}).",
            "Logged time is partly self-reported (per-goal estimates) and partly measured (Focus Sessions), so treat the hours axis as a good estimate rather than a stopwatch.",
        ],
        "weeks_of_history": len(weekly),
        "generated_at": datetime.now().isoformat(),
    }


# ---------------------------------------------------------------------------
# Persistence
# ---------------------------------------------------------------------------

def save_whatif(conn, uid: int, category: str, payload: dict):
    cur = conn.cursor()
    cur.execute(
        """INSERT INTO whatif_cache (user_id, category, payload, lever_key, model_used, generated_at)
           VALUES (%s, %s, %s, %s, %s, NOW())
           ON DUPLICATE KEY UPDATE payload=VALUES(payload), lever_key=VALUES(lever_key),
               model_used=VALUES(model_used), generated_at=NOW()""",
        (uid, category, json.dumps(payload),
         (payload.get("lever") or {}).get("key"),
         (payload.get("model") or {}).get("method")),
    )
    conn.commit()
    cur.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--user", type=int, default=None, help="Only build grids for this user id")
    ap.add_argument("--dry-run", action="store_true", help="Compute and print, write nothing")
    args = ap.parse_args()

    print(f"[{datetime.now():%Y-%m-%d %H:%M}] Engine D — building What-If scenario grids...")
    print("  (reusing the already-trained models; nothing is retrained here)")

    conn = connect_db()
    if args.user:
        users = pd.read_sql("SELECT id, full_name FROM users WHERE id = %s", conn, params=(args.user,))
    else:
        users = pd.read_sql("SELECT id, full_name FROM users", conn)

    if users.empty:
        print("No users found — register an account in the app first.")
        return

    builders = [
        ("finance", build_finance_whatif),
        ("habits", build_habits_whatif),
        ("productivity", build_productivity_whatif),
    ]

    for _, user in users.iterrows():
        uid, name = int(user["id"]), user["full_name"]
        print(f"\n--- {name} (user_id={uid}) ---")
        for category, builder in builders:
            try:
                payload = builder(conn, uid)
            except Exception as e:
                # One category failing must never take the others down with it.
                print(f"  {category:<13} ERROR: {type(e).__name__}: {e}")
                payload = {"status": "error",
                           "message": "The simulator could not be built for this category. "
                                      "Re-run ml/whatif_engine.py after logging more data.",
                           "error": f"{type(e).__name__}: {e}"}

            status = payload.get("status")
            extra = ""
            if status == "ok":
                extra = (f"lever={payload['lever']['key']} "
                         f"current={payload['current_value']}{payload['lever']['unit']} "
                         f"model={payload['model']['method']} "
                         f"points={len(payload['grid'])}")
                if payload.get("burnout_simulation"):
                    extra += " +burnout"
            print(f"  {category:<13} {status:<12} {extra}")

            if not args.dry_run:
                save_whatif(conn, uid, category, payload)

    conn.close()
    if args.dry_run:
        print("\nDry run — nothing was written.")
    else:
        print("\nDone. Open the What-If Lab in the app to see the results.")


if __name__ == "__main__":
    main()
