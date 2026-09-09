"""
evaluate_habit_forecast.py
---------------------------
Held-out accuracy check for the HABIT/PRODUCTIVITY side of forecast_series()
(the counterpart to evaluate_forecast.py, which checks the FINANCE side).

There's no habit-forecasting equivalent of a Kaggle benchmark bundled with
this project (see forecasting.py's own note: "Habit / goal forecasting —
own data only — no Kaggle equivalent for this"), so this script borrows
ml/data/external/modern_teen_mental_health_main.csv — the same benchmark
burnout_model.py already trains on — purely as a stand-in *time series* to
validate the forecasting engine itself against, since it's the one bundled
dataset with real day-by-day granularity (1,000 students x 30 consecutive
days) rather than a single snapshot.

It builds two DAILY population-level series (not weekly — 30 days is too
short to backtest a weekly series meaningfully, see the module docstring in
forecasting.py's own habit-forecasting section for why more history helps):
  - habit_completion_pct: % of students, averaged per day, who exercised,
    journaled or meditated that day (mirrors the app's own completion_pct)
  - avg_mood: mean self-reported mood (1-10 scale) across all students, per
    day (a proxy for the "Productivity Score" style metric)

Then, same as evaluate_forecast.py: chronological 92/8 split, fit on
training days, forecast forward exactly as many days as were held out,
report MAE / RMSE / MAPE / MASE against the real held-out days.

Usage:
    python evaluate_habit_forecast.py
    python evaluate_habit_forecast.py --test-size 0.08
    python evaluate_habit_forecast.py --file data/external/modern_teen_mental_health_main.csv --test-size 0.15
"""

import argparse
import os
import sys

import numpy as np
import pandas as pd

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from forecasting import forecast_series
from evaluate_forecast import _mae, _rmse, _mape, _mase

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_FILE = os.path.join(HERE, "data", "external", "modern_teen_mental_health_main.csv")


def build_daily_series(df: pd.DataFrame) -> pd.DataFrame:
    """
    Collapses the 1,000-students x 30-days check-in table into two
    population-level daily series, indexed by date:
      - habit_completion_pct: mean of (exercised_today, journaled_today,
        meditated_today), as a 0-100 completion rate — same shape as the
        app's own completion_pct in build_habit_forecast().
      - avg_mood: mean self-reported mood (dataset's own 1-10 scale).
    """
    df = df.copy()
    df["date"] = pd.to_datetime(df["date"])
    for c in ["exercised_today", "journaled_today", "meditated_today"]:
        df[c] = df[c].astype(bool).astype(int)

    daily = df.groupby("date").agg(
        exercised=("exercised_today", "mean"),
        journaled=("journaled_today", "mean"),
        meditated=("meditated_today", "mean"),
        avg_mood=("mood", "mean"),
    )
    daily["habit_completion_pct"] = (
        (daily["exercised"] + daily["journaled"] + daily["meditated"]) / 3 * 100
    )
    return daily[["habit_completion_pct", "avg_mood"]].sort_index()


def split_train_test(daily: pd.DataFrame, test_size: float):
    n_test = max(1, int(round(len(daily) * test_size)))
    return daily.iloc[: len(daily) - n_test], daily.iloc[len(daily) - n_test :]


def evaluate_column(train: pd.DataFrame, test: pd.DataFrame, col: str, unit: str):
    train_series = train[col]
    test_series = test[col]

    if len(train_series) < 2:
        print(f"  [{col}] Not enough training days ({len(train_series)}) to fit a model.")
        return

    periods = len(test_series)
    preds, method, mae_bt, rmse_bt, _scores, _lo, _hi = forecast_series(train_series, periods)

    actual = test_series.values.tolist()
    mae = _mae(actual, preds)
    rmse = _rmse(actual, preds)
    mape = _mape(actual, preds)
    mase = _mase(actual, preds, train_series.values.tolist())
    accuracy = None if mape is None else round(max(0.0, 100.0 - mape), 1)

    print(f"\n  [{col}]")
    print(f"    Training days : {len(train_series)}  (winning model on the backtest: {method})")
    print(f"    Held-out days : {periods}")
    print(f"    {'Date':<12} {'Actual':>10} {'Predicted':>10}")
    for d, a, p in zip(test.index, actual, preds):
        print(f"    {str(d.date()):<12} {a:>9.2f}{unit} {p:>9.2f}{unit}")
    print(f"    MAE  : {mae:.3f}{unit}")
    print(f"    RMSE : {rmse:.3f}{unit}")
    print(f"    MAPE : {'n/a' if mape is None else f'{mape:.1f}%'}")
    print(f"    MASE : {'n/a' if mase is None else f'{mase:.2f}'}  (<1.0 = beats naive last-day-repeats baseline)")
    print(f"    Accuracy (100% - MAPE): {'n/a' if accuracy is None else f'{accuracy}%'}")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--file", default=DEFAULT_FILE)
    ap.add_argument("--test-size", type=float, default=0.08)
    args = ap.parse_args()

    if not os.path.exists(args.file):
        print(f"File not found: {args.file}")
        sys.exit(1)
    if not (0 < args.test_size < 1):
        print("--test-size must be between 0 and 1 (e.g. 0.08 for 8%).")
        sys.exit(1)

    raw = pd.read_csv(args.file)
    daily = build_daily_series(raw)
    print(f"Loaded {len(raw):,} rows ({raw['student_id'].nunique():,} students) from {os.path.basename(args.file)}")
    print(f"Collapsed to {len(daily)} population-level daily data points "
          f"({daily.index.min().date()} -> {daily.index.max().date()})")

    train, test = split_train_test(daily, args.test_size)
    print(f"Split: {len(train)} days train ({100 * (1 - args.test_size):.0f}%) / "
          f"{len(test)} days test ({100 * args.test_size:.0f}%) — chronological tail cut")

    evaluate_column(train, test, "habit_completion_pct", "%")
    evaluate_column(train, test, "avg_mood", "/10")


if __name__ == "__main__":
    main()
