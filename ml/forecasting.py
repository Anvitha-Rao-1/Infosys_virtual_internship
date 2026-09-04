"""
forecasting.py
--------------
Small, dependency-light forecasting helpers used by train_model.py.

Everything in this file is pure (no database, no file I/O) so it can be
unit-tested with plain pandas/numpy data. train_model.py is the only file
that talks to MySQL and to the benchmark dataset file on disk (a Kaggle
download, or the synthetic one bundled at ml/data/finance_benchmark.xlsx —
see ml/data/README_DATASET.md for how it was generated and how to swap in
a real Kaggle CSV/XLSX instead).

The methods used are intentionally simple and explainable:
  - Linear Regression on a month index (captures an overall upward/downward trend)
  - Moving Average (captures "recent typical level", ignores trend)
A backtest picks whichever of the two predicted the most recent real
months more accurately, per user, per metric (income / expense).

No black-box model, no external AI API call — every number on the
Forecast page can be traced back to a function in this file.
"""

from __future__ import annotations
import numpy as np
import pandas as pd
from sklearn.linear_model import LinearRegression


# ---------------------------------------------------------------------------
# Kaggle dataset ingestion
# ---------------------------------------------------------------------------

# Common column name variants seen across Kaggle personal-finance /
# household-transactions datasets. We match case-insensitively and pick the
# first candidate that exists in the uploaded CSV.
_DATE_CANDIDATES = ["date", "txn_date", "transaction_date", "posted_date"]
_CATEGORY_CANDIDATES = ["category", "category name", "sub_category", "subcategory", "type_of_expense"]
_AMOUNT_CANDIDATES = ["amount", "value", "transaction_amount", "amt"]
_TYPE_CANDIDATES = ["income/expense", "income_expense", "type", "transaction_type", "cash_flow"]


def _find_column(columns, candidates):
    lower_map = {c.lower().strip(): c for c in columns}
    for cand in candidates:
        if cand in lower_map:
            return lower_map[cand]
    return None


def load_kaggle_dataset(path: str) -> pd.DataFrame:
    """
    Loads a benchmark personal-finance / household-transactions dataset —
    either a Kaggle download or the bundled synthetic one — from .csv,
    .xlsx or .xls, and normalizes it to columns: date (datetime64),
    category (str), amount (float, always positive), type ('income' or
    'expense').

    Raises ValueError with a clear message if it can't find the columns
    it needs, so the failure is easy to fix from the file's actual headers.
    """
    path_lower = str(path).lower()
    if path_lower.endswith((".xlsx", ".xls")):
        raw = pd.read_excel(path)
    else:
        raw = pd.read_csv(path)
    cols = list(raw.columns)

    date_col = _find_column(cols, _DATE_CANDIDATES)
    cat_col = _find_column(cols, _CATEGORY_CANDIDATES)
    amt_col = _find_column(cols, _AMOUNT_CANDIDATES)
    type_col = _find_column(cols, _TYPE_CANDIDATES)

    missing = [name for name, col in [
        ("date", date_col), ("amount", amt_col)
    ] if col is None]
    if missing:
        raise ValueError(
            f"Dataset at {path} is missing columns for: {', '.join(missing)}. "
            f"Found columns: {cols}. Edit _DATE_CANDIDATES / _AMOUNT_CANDIDATES "
            f"in forecasting.py to add your file's exact header name."
        )

    df = pd.DataFrame()
    # Kaggle finance CSVs mix date formats (DD/MM/YYYY is common outside the US).
    # Try the default parse, and fall back to dayfirst if that leaves too many
    # unparseable rows — whichever interpretation loses fewer rows wins.
    parsed_default = pd.to_datetime(raw[date_col], errors="coerce", dayfirst=False)
    parsed_dayfirst = pd.to_datetime(raw[date_col], errors="coerce", dayfirst=True)
    df["date"] = parsed_default if parsed_default.isna().sum() <= parsed_dayfirst.isna().sum() else parsed_dayfirst
    df["category"] = raw[cat_col].astype(str).str.strip().str.title() if cat_col else "Other"
    df["amount"] = pd.to_numeric(raw[amt_col], errors="coerce").abs()

    if type_col:
        norm = raw[type_col].astype(str).str.strip().str.lower()
        df["type"] = np.where(norm.str.contains("income|credit|salary"), "income", "expense")
    else:
        # No explicit income/expense column — assume everything is a spend,
        # which is the common case for pure "household transactions" datasets.
        df["type"] = "expense"

    df = df.dropna(subset=["date", "amount"])
    return df


# ---------------------------------------------------------------------------
# Aggregation
# ---------------------------------------------------------------------------

def monthly_totals(df: pd.DataFrame) -> pd.DataFrame:
    """
    df must have columns: date, amount, type.
    Returns a DataFrame indexed by month (Period), columns: income, expense.
    Months with no rows of a given type are filled with 0.
    """
    if df.empty:
        return pd.DataFrame(columns=["income", "expense"])
    work = df.copy()
    work["month"] = work["date"].dt.to_period("M")
    pivot = work.pivot_table(index="month", columns="type", values="amount", aggfunc="sum", fill_value=0)
    for col in ["income", "expense"]:
        if col not in pivot.columns:
            pivot[col] = 0.0
    return pivot[["income", "expense"]].sort_index()


def category_shares(df: pd.DataFrame, type_filter: str = "expense") -> dict:
    """Returns {category: share_of_total} for the given type, shares sum to 1."""
    subset = df[df["type"] == type_filter]
    if subset.empty:
        return {}
    totals = subset.groupby("category")["amount"].sum()
    grand_total = totals.sum()
    if grand_total <= 0:
        return {}
    return (totals / grand_total).to_dict()


def blend_category_shares(user_shares: dict, benchmark_shares: dict, user_weight: float) -> dict:
    """
    Blends the user's own category proportions with the Kaggle benchmark's
    proportions. user_weight is 0..1 — how much to trust the user's own data
    (should grow as they log more months of their own transactions).
    """
    user_weight = max(0.0, min(1.0, user_weight))
    categories = set(user_shares) | set(benchmark_shares)
    blended = {}
    for cat in categories:
        u = user_shares.get(cat, 0.0)
        b = benchmark_shares.get(cat, 0.0)
        blended[cat] = user_weight * u + (1 - user_weight) * b
    total = sum(blended.values()) or 1.0
    return {k: v / total for k, v in blended.items()}


# ---------------------------------------------------------------------------
# Forecasting methods
# ---------------------------------------------------------------------------

def forecast_linear(series: pd.Series, periods: int) -> np.ndarray:
    """
    Fits scikit-learn's LinearRegression on (month_index -> amount),
    then projects `periods` months ahead. This is the "Linear Regression"
    model shown on the Forecast page.
    """
    y = series.values.astype(float)
    if len(y) < 2:
        base = y[-1] if len(y) else 0.0
        return np.full(periods, base)
    x = np.arange(len(y)).reshape(-1, 1)
    model = LinearRegression()
    model.fit(x, y)
    future_x = np.arange(len(y), len(y) + periods).reshape(-1, 1)
    preds = model.predict(future_x)
    return np.clip(preds, 0, None)


def forecast_moving_average(series: pd.Series, periods: int, window: int = 3) -> np.ndarray:
    """Projects the average of the last `window` months forward, flat."""
    y = series.values.astype(float)
    if len(y) == 0:
        return np.zeros(periods)
    recent = y[-window:] if len(y) >= window else y
    avg = recent.mean()
    return np.full(periods, max(avg, 0.0))


def _rmse(a, b):
    return float(np.sqrt(np.mean((np.array(a) - np.array(b)) ** 2)))


def _mae(a, b):
    return float(np.mean(np.abs(np.array(a) - np.array(b))))


def backtest_and_pick_best(series: pd.Series):
    """
    Holds out up to the last 3 months (or fewer if not enough history),
    re-fits each method on everything before, and scores it against the
    real values. Returns (best_method_name, mae, rmse) for whichever
    method wins, falling back gracefully with tiny histories.
    """
    y = series.values.astype(float)
    if len(y) < 4:
        # A line fit through 2-3 points extrapolates unstably (a single big
        # swing can send the projection to zero or beyond) — moving_average
        # is the safe default until there's enough history to backtest.
        return ("moving_average", None, None)
    n_holdout = min(3, len(y) - 2)

    methods = {
        "linear_trend": lambda train, k: forecast_linear(pd.Series(train), k),
        "moving_average": lambda train, k: forecast_moving_average(pd.Series(train), k),
    }
    scores = {name: {"actual": [], "pred": []} for name in methods}

    for i in range(len(y) - n_holdout, len(y)):
        train = y[:i]
        actual = y[i]
        for name, fn in methods.items():
            pred = fn(train, 1)[0]
            scores[name]["actual"].append(actual)
            scores[name]["pred"].append(pred)

    best_name, best_mae, best_rmse = None, None, None
    for name, s in scores.items():
        mae = _mae(s["actual"], s["pred"])
        rmse = _rmse(s["actual"], s["pred"])
        if best_mae is None or mae < best_mae:
            best_name, best_mae, best_rmse = name, mae, rmse

    return (best_name, round(best_mae, 2), round(best_rmse, 2))


def forecast_series(series: pd.Series, periods: int):
    """
    Picks the best-scoring method via backtest, then forecasts `periods`
    steps ahead using ALL available history with that method.
    Returns (predictions: list[float], method_name: str, mae, rmse).
    """
    method_name, mae, rmse = backtest_and_pick_best(series)
    if method_name == "moving_average":
        preds = forecast_moving_average(series, periods)
    else:
        preds = forecast_linear(series, periods)
    return [round(float(p), 2) for p in preds], method_name, mae, rmse


# ---------------------------------------------------------------------------
# Habit / goal forecasting (own data only — no Kaggle equivalent for this)
# ---------------------------------------------------------------------------

def forecast_weekly_completion(weekly_pct: pd.Series, periods: int = 2):
    """
    weekly_pct: a pandas Series of weekly completion percentages (0-100),
    oldest first, indexed by week number. Returns projected percentages for
    the next `periods` weeks (clamped 0-100) plus the method used.
    """
    if len(weekly_pct) == 0:
        return [], "insufficient_data", None, None
    preds, method, mae, rmse = forecast_series(weekly_pct, periods)
    preds = [max(0.0, min(100.0, p)) for p in preds]
    return preds, method, mae, rmse
