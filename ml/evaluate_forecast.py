"""
evaluate_forecast.py
---------------------
Held-out accuracy check for the forecasting engine in forecasting.py.

Splits a transactions CSV/XLSX chronologically into a 92% training portion
and an 8% held-out testing portion (NOT a random row split — this is a time
series: a random split would let the model "see" months that fall before its
last training month, which is not the situation the real app is ever in),
aggregates both to monthly income/expense totals with the same
monthly_totals() used in train_model.py, fits the forecaster on the training
months only, forecasts forward exactly as many months as were held out, and
reports MAE / RMSE / MAPE / accuracy (100% - MAPE) against the real held-out
months.

This is an offline sanity check you run from the command line — it does not
touch the database or forecast_cache table, and it never runs as part of
train_model.py or the live app.

Usage:
    python evaluate_forecast.py
    python evaluate_forecast.py --file data/personal_transactions_raw.csv
    python evaluate_forecast.py --file data/personal_transactions_raw.csv --test-size 0.08
    python evaluate_forecast.py --file data/kaggle_raw_daily_household_transactions.csv --test-size 0.08

Requires: pip install -r requirements.txt (same deps as train_model.py).
"""

import argparse
import os
import sys

import numpy as np
import pandas as pd

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from forecasting import load_kaggle_dataset, monthly_totals, forecast_series

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_FILE = os.path.join(HERE, "data", "personal_transactions_raw.csv")


def split_train_test(df: pd.DataFrame, test_size: float):
    """
    Chronological split: sorts by date, takes the LAST `test_size` fraction
    of rows as the held-out test set and everything before it as training.
    A plain sklearn train_test_split(shuffle=True) would scatter random rows
    from every month into both sides, which defeats the point of testing a
    forecaster (it would then partly be trained on the same months it's
    scored against). Sorting + a tail cut is the standard way to backtest a
    time series honestly.
    """
    df = df.sort_values("date").reset_index(drop=True)
    n_test = max(1, int(round(len(df) * test_size)))
    train_df = df.iloc[: len(df) - n_test].copy()
    test_df = df.iloc[len(df) - n_test :].copy()
    return train_df, test_df


def _mae(a, b):
    return float(np.mean(np.abs(np.array(a) - np.array(b))))


def _rmse(a, b):
    return float(np.sqrt(np.mean((np.array(a) - np.array(b)) ** 2)))


def _mape(a, b):
    a = np.array(a, dtype=float)
    b = np.array(b, dtype=float)
    mask = a != 0
    if not np.any(mask):
        return None
    return float(np.mean(np.abs((a[mask] - b[mask]) / a[mask])) * 100)


def _mase(actual, preds, train_series):
    """
    Mean Absolute Scaled Error: MAE of the model / MAE of a naive
    "next period = last period" baseline fit on the training series.
    <1 means the model beats naive persistence, >1 means it's worse.
    Unlike MAPE this doesn't blow up or go undefined when an actual value is
    zero (exactly the failure mode seen on zero-inflated series), so it's a
    more honest number to trust on spiky/zero-heavy months.
    """
    train = np.array(train_series, dtype=float)
    if len(train) < 2:
        return None
    naive_mae = float(np.mean(np.abs(np.diff(train))))
    if naive_mae == 0:
        return None
    return _mae(actual, preds) / naive_mae


def evaluate_column(train_monthly: pd.DataFrame, test_monthly: pd.DataFrame, col: str):
    train_series = train_monthly[col]
    test_series = test_monthly[col]

    if len(train_series) < 2:
        print(f"  [{col}] Not enough training months ({len(train_series)}) to fit a model. "
              f"Try a smaller --test-size or a longer dataset.")
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
    print(f"    Training months : {len(train_series)}  (winning model on the backtest: {method})")
    print(f"    Held-out months : {periods}")
    print(f"    {'Month':<10} {'Actual':>12} {'Predicted':>12}")
    for m, a, p in zip(test_monthly.index, actual, preds):
        print(f"    {str(m):<10} {a:>12,.2f} {p:>12,.2f}")
    print(f"    MAE  : {mae:,.2f}")
    print(f"    RMSE : {rmse:,.2f}")
    print(f"    MAPE : {'n/a' if mape is None else f'{mape:.1f}%'}  (unstable/inflated when actuals are near zero)")
    print(f"    MASE : {'n/a' if mase is None else f'{mase:.2f}'}  (<1.0 = beats a naive last-month-repeats baseline)")
    print(f"    Accuracy (100% - MAPE): {'n/a' if accuracy is None else f'{accuracy}%'}")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--file", default=DEFAULT_FILE,
                     help=f"Path to the transactions CSV/XLSX to evaluate (default: {DEFAULT_FILE})")
    ap.add_argument("--test-size", type=float, default=0.08,
                     help="Fraction of rows (chronological tail) held out for testing (default: 0.08 = 8%%)")
    args = ap.parse_args()

    if not os.path.exists(args.file):
        print(f"File not found: {args.file}")
        sys.exit(1)
    if not (0 < args.test_size < 1):
        print("--test-size must be between 0 and 1 (e.g. 0.08 for 8%).")
        sys.exit(1)

    df = load_kaggle_dataset(args.file)
    if df.empty:
        print("Dataset loaded but is empty after parsing — check the file's columns/date format.")
        sys.exit(1)

    train_df, test_df = split_train_test(df, args.test_size)
    print(f"Loaded {len(df):,} rows from {os.path.basename(args.file)}")
    print(f"Split: {len(train_df):,} rows train ({100 * (1 - args.test_size):.0f}%) / "
          f"{len(test_df):,} rows test ({100 * args.test_size:.0f}%) — chronological tail cut")
    print(f"Train date range: {train_df['date'].min().date()} -> {train_df['date'].max().date()}")
    print(f"Test  date range: {test_df['date'].min().date()} -> {test_df['date'].max().date()}")

    train_monthly = monthly_totals(train_df)
    full_monthly = monthly_totals(df)
    # Test months = whole-dataset months not present in the training aggregate.
    test_monthly = full_monthly.loc[~full_monthly.index.isin(train_monthly.index)]

    if test_monthly.empty:
        print("\nThe held-out rows didn't add a full extra month beyond training "
              "(monthly_totals() drops any still-in-progress month) — try a larger --test-size.")
        sys.exit(1)

    print(f"\nEvaluating forecast_series() on {len(test_monthly)} held-out month(s): "
          f"{[str(m) for m in test_monthly.index]}")

    for col in ["income", "expense"]:
        evaluate_column(train_monthly, test_monthly, col)


if __name__ == "__main__":
    main()
