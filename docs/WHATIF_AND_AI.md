# What-If Simulation & the Hugging Face AI Layer

Technical documentation for the layer built **on top of** the existing ML
forecasting system. Written for someone who has to maintain, extend or
defend this code — it states what each piece does, what it deliberately does
*not* do, and why.

The short version:

```
KAGGLE / BENCHMARK TRAINING DATA
            ↓
     EXISTING ML MODEL            ← unchanged by this work
            ↓
     MODEL PREDICTION
            ↓
    YOUR CURRENT DATA
            ↓
    WHAT-IF SIMULATION            ← modified input, SAME model
            ↓
 ┌──────────┼──────────┐
 ↓          ↓          ↓
EXPECTED  IMPROVED   RISK
 └──────────┼──────────┘
            ↓
   SCENARIO COMPARISON
            ↓
      VISUALISATION
            ↓
   STRUCTURED RESULTS             ← ~20 numbers, nothing else
            ↓
      HUGGING FACE                ← language only, never numbers
            ↓
    AI INTERPRETATION
            ↓
   PERSONALISED INSIGHT
            ↓
      RECOMMENDATION
```

---

## 1. What already existed (and was not touched)

| Engine | File | Model | Trained on | Output | Cached in |
|---|---|---|---|---|---|
| A — Finance forecast | `ml/forecasting.py`, `ml/train_model.py` | Linear Regression / ARIMA(1,1,1) / XGBoost, chosen per-metric by backtest | The user's own monthly income & expense series. A benchmark CSV supplies category proportions only. | Next 3 months of income, expense, profit, margin, cash flow + 95% CI | `forecast_cache` |
| B — Habit / productivity forecast | same | same three candidates | The user's own weekly completion % and Productivity Score | Next 2 weeks + 95% CI | `forecast_cache` |
| C — Burnout risk | `ml/burnout_model.py` | `RandomForestClassifier` (200 trees, depth 6) | `ml/data/external/modern_teen_mental_health_main.csv` — 30,000 daily check-ins | Risk probability + top risk/protective factors | `burnout_predictions` |

**No file in that table was modified by this work.** Engine D imports from
them; it does not edit them.

The only pre-existing files changed at all are listed in §8, and each change
is a bug fix or an additive line, never a change to model logic.

---

## 2. Engine D — the What-If engine

`ml/whatif_engine.py`. One job:

> take the existing model, feed it a modified input, record what it said.

### How a scenario is produced

1. **Baseline.** Run the existing `forecast_series()` on the user's real,
   unmodified history. This picks the winning model by the same backtest the
   app already uses, and reproduces exactly what the Analyse page shows.
2. **Lock the model.** Every scenario is then forecast with the *same*
   winning method, via `forecast_with()`, rather than re-running model
   selection per scenario. If scenario A were forecast by ARIMA and B by
   XGBoost, part of the difference between them would be a model artifact
   rather than an effect of the lever.
3. **Modify the input.** The lever multiplicatively rescales the historical
   input series. The *shape* and *trend* — what the models actually learn
   from — are preserved; only the *level* moves.
4. **Re-predict.** The modified series goes through the same forecast
   function. Because ARIMA and XGBoost are non-linear, the output is **not**
   simply the baseline times the lever factor.
5. **Record.** Every grid point is stored in `whatif_cache`, so the PHP UI
   renders a slider, a comparison and a response curve with zero runtime
   Python.

### The three levers

| Category | Lever | Grid | How it reaches the model | Predicted metric |
|---|---|---|---|---|
| Finance | Savings rate (% of income not spent) | 0–70%, step 5 (15 points) | Expense history rescaled by `f = (1-r) / (1-r_now)`. Income is **never** modified — saving more is a spending decision, not an earning one. | Projected savings next month (₹) |
| Habits | Check-ins per week | 0–7, step 0.5 (15 points) | `days = completion_pct / 100 × 7`, so the lever maps onto a real model input exactly. Completion and Productivity Score series both rescaled by `f = target_days / current_days`. | Projected completion rate next week (%) |
| Productivity | Focused hours per day | 0.5–6.0, step 0.5 (12 points) | Only the *time* half of the score moves: `time_pct` rescaled, then the score rebuilt with its own formula `0.6×completion + 0.4×time`, then forecast. | Projected Productivity Score next week (/100) |

Habits and Productivity deliberately answer **different questions**: "I showed
up on more days" vs. "I worked longer on the days I showed up".

### The burnout sub-simulation — the purest black-box reuse

`build_burnout_whatif()` is the clearest example of using the existing model
as a black box:

1. Loads `ml/data/external/burnout_model.joblib` — the RandomForest Engine C
   **already trained**.
2. Reads the feature vector Engine C **already computed** for this user, from
   `burnout_predictions.payload['features_used']`.
3. Changes **one value** in it.
4. Calls `.predict_proba()`.

Nothing is re-fitted and no feature is re-derived. `burnout_model.py` is not
even imported — its own stored output is read instead, so there is no way for
this code to accidentally change how Engine C behaves. Column order is taken
from `clf.feature_names_in_` so a mismatch can never silently reorder
features. If the model file or the user's stored prediction is missing, the
block is omitted and the rest of the habit simulator still works.

### Scenario selection

`pick_scenarios()` returns three points on the grid:

- **EXPECTED** — the grid point nearest what the user is actually doing.
- **IMPROVED** — a fixed step better, clamped to the grid.
- **RISK** — a fixed step worse, clamped to the grid.

These are deliberately *not* the best and worst points on the grid: a
scenario is only useful if it is a plausible change, not a fantasy. When the
user is already at an end of the slider, IMPROVED or RISK collapses onto
EXPECTED, and the UI says so rather than showing a meaningless zero.

### Honesty checks

`response_summary()` inspects the whole grid and flags two cases the UI then
states plainly instead of drawing three near-identical bars:

- **flat** — the prediction barely responds to the lever. A real result
  ("something else is driving this"), not a bug.
- **non-monotonic** — the response wobbles instead of moving steadily. Tree
  models do this because they predict in steps.

### Interpolation policy

**The slider only stops on values the model was actually run on.** PHP's
`whatif_point()` returns `null` for an unknown value and the JS snaps to the
nearest grid point. A prediction that was never made is never displayed.

---

## 3. Buy vs. Rent — a calculation, not a prediction

`buy_vs_rent()` in `includes/whatif.php`. **Nothing here is predicted,
learned or trained.**

It is deliberately kept *out* of `ml/whatif_engine.py` so the separation is
structural rather than just a comment, and it is labelled as arithmetic
everywhere it appears in the UI.

Why it cannot use the existing model: that model was trained on monthly
income/expense series. It has never seen a property price, a loan rate or a
rent, so asking it this question would mean inventing an answer.

Method — a standard net-worth comparison at the horizon:

- **Buy**: pay the deposit, then EMI (standard amortising-loan formula) plus
  upkeep monthly. Net worth = property value − outstanding loan.
- **Rent**: pay rent rising with inflation, invest the deposit, and invest the
  monthly difference whenever renting is cheaper. Net worth = portfolio value.

Every rate is an assumption the user sets. The output is exactly as good as
those assumptions.

---

## 4. The Hugging Face AI layer

`includes/ai_insight.php` + `ai_insight.php`.

### It produces no numbers

Every figure in this project comes from a trained model. The language model
is handed those already-computed numbers and asked to do the one thing a
regression model cannot: turn a table into readable prose.

Three mechanisms enforce this:

1. **The system prompt** forbids inventing, estimating, recomputing or
   rounding any number.
2. **The user prompt** writes every figure out as literal text — including
   the differences and percentage changes it might otherwise be tempted to
   work out — so there is nothing left to compute.
3. **The payload is tiny.** `whatif_ai_summary()` sends about twenty
   already-computed numbers. No raw transactions, check-ins, or personal
   details ever leave the server. The UI shows the exact payload under
   "Exactly what was sent to the AI".

### Model selection

Default: **`meta-llama/Llama-3.1-8B-Instruct`** (served as
`Meta-Llama-3.1-8B-Instruct-Turbo`).

| Requirement | Why this model |
|---|---|
| Actually available | Verified working end to end against the live router from this project. |
| Free / low cost | 8B — comfortably inside the free inference credits. |
| Fast | A ~4-sentence explanation returns in about a second; the UI blocks on it. |
| Good at constrained prose | Follows "explain these, invent nothing" reliably. |
| Swappable | `HF_MODEL` in `.env` — any chat-completion model on the router works. |

#### ⚠ Do not use a reasoning model here

`openai/gpt-oss-20b` was the original default and **had to be replaced**.
Reasoning models (gpt-oss-*, GLM-*-Air and similar) split their output into a
reasoning channel and a final answer. At this task's small token budget the
budget is spent entirely on reasoning, so the call returns **HTTP 200 with an
empty `message.content`** and the app silently degrades to its local writer.

Measured against the live router:

| Model | Result |
|---|---|
| `meta-llama/Llama-3.1-8B-Instruct` | ✅ real prose |
| `deepseek-ai/DeepSeek-V3.2-Exp` | ✅ real prose |
| `openai/gpt-oss-20b` | ❌ 200 + empty content (and intermittently 403) |
| `zai-org/GLM-4.5-Air` | ❌ 200 + empty content |
| `Qwen/Qwen2.5-7B-Instruct`, `mistralai/Mistral-7B-Instruct-v0.3`, `google/gemma-2-2b-it` | ❌ HTTP 400, not served by any provider |

`ai_parse_response()` now detects the empty-content case, checks for a
`reasoning` / `reasoning_content` field, and returns a
`reasoning_only: …` reason naming the fix instead of a bare "empty".

Note also that the router picks a provider for you and **some providers are
region-blocked** — Groq returned an HTTP 403 Cloudflare page from India during
testing. The parser handles a non-JSON body explicitly (`bad_response: …`)
rather than misreporting a CDN interstitial as an empty response.

A reasoning model buys nothing here regardless: the task is summarising
numbers that are already final.

### Transport

OpenAI-compatible, non-streaming:

```
POST https://router.huggingface.co/v1/chat/completions
Authorization: Bearer $HF_API_KEY
{ "model": …, "messages": [system, user], "temperature": 0.3,
  "max_tokens": 320, "stream": false }
```

`temperature: 0.3` — this explains fixed numbers, so the same scenario should
read the same way twice.

cURL is used when available, with a `file_get_contents` stream fallback for
PHP builds without ext/curl.

### Configuration

Never hard-coded. `includes/env.php` reads, in order: real environment
variable → `$_SERVER`/`$_ENV` → the gitignored `.env` file.

```
HF_API_KEY=hf_…          # required only for live AI; see .env.example
HF_MODEL=meta-llama/Llama-3.1-8B-Instruct
HF_API_URL=https://router.huggingface.co/v1/chat/completions
HF_TIMEOUT=20
```

### Graceful degradation — tested, not assumed

Every one of these was exercised against the running app:

| Failure | Behaviour | `reason` returned |
|---|---|---|
| No API key | Local explanation | `no_api_key` |
| Invalid / rejected token | Local explanation | `auth: …` |
| Model not served by any provider | Local explanation | `model_unavailable: …` |
| Rate limited (HTTP 429) | Local explanation | `rate_limit: …` |
| Network unreachable / timeout | Local explanation | `network: …` |
| Empty model response | Local explanation | `empty: …` |
| `ai_insight_cache` table missing | Works, just uncached | — |

In every case the user still gets ML predictions, What-If simulations,
scenario comparison and every chart. Only the written paragraph changes
source, and it is badged **"📝 Written locally"** instead of
**"✨ Written by …"** so the distinction is never hidden.

### The local fallback writer

`ai_fallback_insight()` assembles the same four beats from the same numbers
using rules, not a model. It is careful about three things that are easy to
get wrong:

- **Provenance of accuracy.** A forecasting model's accuracy comes from
  backtesting against *the user's own* past periods; the burnout classifier's
  comes from a held-out split of the *public training dataset*. Claiming the
  latter was "backtested on your own history" would be false, so
  `accuracy_basis` travels with the number.
- **Low accuracy.** Below 60% the wording changes from "well-grounded" to
  "treat this one loosely", with the likely cause.
- **Undefined percentages.** When the baseline is zero, percentage change is
  undefined and is omitted rather than printed as `+—%`.

---

## 5. Data flow, end to end

```
Kaggle / benchmark CSV ──train──▶ burnout_model.joblib
                                        │
User logs data ──▶ MySQL ──┬────────────┤
                           │            ▼
                           │   burnout_model.py ──▶ burnout_predictions
                           │
                           └──▶ train_model.py ──▶ forecast_cache
                                        │
                                        ▼
                             whatif_engine.py                    ← Engine D
                    (same models, modified inputs)
                                        │
                                        ▼
                                  whatif_cache
                                        │
                        ┌───────────────┴───────────────┐
                        ▼                               ▼
                  simulate.php                   ai_insight.php
              (sliders, charts,              (≈20 numbers only)
               scenario comparison)                    │
                                                       ▼
                                          router.huggingface.co
                                                       │
                                                       ▼
                                            explanation + badge
```

No PHP page ever calls Python at request time. The two `run_*.php` endpoints
are optional convenience buttons that shell out, and the app works fine when
they fail.

---

## 6. New files

| File | Purpose |
|---|---|
| `ml/whatif_engine.py` | Engine D — builds the scenario grids |
| `database/migration_whatif.sql` | Additive: `whatif_cache`, `ai_insight_cache` |
| `includes/whatif.php` | PHP read layer + `buy_vs_rent()` |
| `includes/ai_insight.php` | HF client, prompts, cache, local fallback |
| `includes/env.php` | `.env` reader — the only place secrets are read |
| `simulate.php` | The What-If Lab UI (3 tabs) |
| `methodology.php` | "How was this calculated?" + AI concepts |
| `ai_insight.php` | JSON endpoint for the insight button |
| `run_whatif.php` | Optional "Re-simulate" trigger |
| `.env.example` | Key names, no values |
| `docs/WHATIF_AND_AI.md` | This file |

## 7. Charts, and why each type

| Chart | Type | Why | Data |
|---|---|---|---|
| Historical trend & baseline forecast | Line (solid → dashed + CI band) | A quantity ordered in time; solid/dashed separates what happened from what is projected | Real history + baseline forecast |
| Expected vs Improved vs Risk | Grouped bar | Three separate, unordered cases being compared — not a quantity over time | The three grid points |
| Response curve | Scatter + least-squares fit + Pearson r | Shows the *whole shape* of the relationship, not three points of it | Every grid point |
| Burnout risk across sleep | Line, two series, both solid | Model output at each input level; both solid because neither is a forecast over time | One `predict_proba()` call per point |
| Buy vs rent net worth | Line, two series, both solid | Both are arithmetic, not forecasts; the crossing point is the break-even year | `buy_vs_rent()` yearly rows |

Every chart is drawn by the existing `svg_*_chart()` helpers in
`includes/helpers.php` and wrapped in the existing `chart_card()`.

## 8. Changes to pre-existing files

Every one is additive or a bug fix. **No model logic was changed.**

| File | Change | Why |
|---|---|---|
| `run_forecast.php` | **Bug fix**: convert shell output to UTF-8 before `json_encode` | Pre-existing bug. `train_model.py` prints `₹` and `—`, which the Windows Python console emits as cp1252 — not valid UTF-8. `json_encode()` returns `false` on invalid UTF-8, so the endpoint returned an empty 200 body and the "Retrain now" button *always* reported failure even when the retrain succeeded. |
| `includes/header.php` | Two nav links added | Reach the new pages |
| `includes/icons.php` | Two icons added (`sliders`, `book`) | Same construction as the existing set |
| `css/style.css` | Block appended at the end | Nothing above it modified |
| `.gitignore` | `.env` added | Keep the token out of git |

## 9. Running it

```bash
# once
mysql -u root -P 3307 habit_tracker < database/migration_whatif.sql
copy .env.example .env          # then paste your HF token in (optional)

# after logging new data
python ml/train_model.py        # existing forecasts
python ml/burnout_model.py      # existing burnout scores
python ml/whatif_engine.py      # scenario grids  ← must run after the two above
```

`whatif_engine.py` reads `burnout_predictions`, so run it **after**
`burnout_model.py`, or the burnout sub-simulation is skipped.

Useful flags: `--user <id>` for one account, `--dry-run` to compute and print
without writing.
