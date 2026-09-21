<?php
/**
 * includes/whatif.php
 * ------------------------------------------------------------------
 * The PHP READ layer for the What-If / Scenario Simulation engine.
 *
 * It contains no model and no forecasting maths. Every prediction it
 * returns was produced by ml/whatif_engine.py, which in turn produced it
 * by feeding a modified input through the project's EXISTING trained
 * models. This file's whole job is to look those precomputed predictions
 * up, pair them into comparisons, and format them for the page — exactly
 * the relationship includes/helpers.php's get_forecast() already has with
 * ml/train_model.py.
 *
 * The one exception is buy_vs_rent(), which is deliberately NOT a model
 * prediction and says so in its own docblock and everywhere it appears in
 * the UI: it is ordinary loan/rent arithmetic.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/db.php';

/* ============================================================
   Reading the cache ml/whatif_engine.py writes
   ============================================================ */

/**
 * Returns the decoded what-if payload for one category
 * ('finance' | 'habits' | 'productivity'), or null if the engine has
 * never been run for this user.
 */
function get_whatif($pdo, $uid, $category) {
    $stmt = $pdo->prepare("SELECT payload, lever_key, model_used, generated_at FROM whatif_cache WHERE user_id=? AND category=?");
    $stmt->execute([$uid, $category]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $payload = json_decode($row['payload'], true);
    if (!is_array($payload)) return null;
    $payload['_generated_at'] = $row['generated_at'];
    return $payload;
}

/** True when this payload actually has a usable simulation in it. */
function whatif_ready($payload): bool {
    return $payload && ($payload['status'] ?? null) === 'ok' && !empty($payload['grid']);
}

/**
 * The grid point for an exact lever value. Returns null if that value was
 * never simulated — we never interpolate between two predictions to invent
 * a third the model did not actually make.
 */
function whatif_point($payload, $value) {
    foreach ($payload['grid'] as $g) {
        if (abs(((float)$g['value']) - ((float)$value)) < 1e-6) return $g;
    }
    return null;
}

/* ============================================================
   Formatting
   ============================================================ */

/** Formats a prediction in its metric's own units (₹1,240 / 84.2% / 71/100). */
function whatif_format_metric($metric, $value): string {
    if ($value === null) return '—';
    $prefix = $metric['prefix'] ?? '';
    $suffix = $metric['suffix'] ?? '';
    $decimals = ($prefix === '₹') ? 0 : 1;
    return $prefix . number_format((float)$value, $decimals) . $suffix;
}

/** Formats a lever value in its own units (30% / 5.5 days/wk / 2.5h/day). */
function whatif_format_lever($lever, $value): string {
    if ($value === null) return '—';
    $step = (float)($lever['step'] ?? 1);
    $decimals = ($step < 1) ? 1 : 0;
    $unit = $lever['unit'] ?? '';
    return number_format((float)$value, $decimals) . $unit;
}

/* ============================================================
   Scenario comparison
   ============================================================ */

/**
 * Builds the EXPECTED / IMPROVED / RISK comparison rows.
 *
 * EXPECTED is always the baseline every other scenario is measured
 * against — "carry on as you are". The difference and percentage change
 * columns are therefore each scenario minus EXPECTED, never minus zero.
 *
 * `$override` lets the interactive slider replace the EXPECTED row with
 * whatever grid point the user has dragged to, so the comparison always
 * answers "compared to what I'm looking at right now".
 */
function whatif_scenarios($payload, $override = null): array {
    $metric = $payload['metric'];
    $lever = $payload['lever'];
    $values = $payload['scenario_values'];
    if ($override !== null && whatif_point($payload, $override)) {
        $values['expected'] = $override;
    }

    $rows = [];
    $base_point = whatif_point($payload, $values['expected']);
    $base = $base_point ? (float)$base_point['headline'] : null;

    $meta = [
        'expected' => ['label' => 'Expected',  'blurb' => 'Carry on at your current level.',      'tone' => 'neutral'],
        'improved' => ['label' => 'Improved',  'blurb' => 'Move the lever in the better direction.', 'tone' => 'good'],
        'risk'     => ['label' => 'Risk',      'blurb' => 'Slip back to a lower level.',          'tone' => 'attention'],
    ];

    foreach (['expected', 'improved', 'risk'] as $key) {
        $val = $values[$key] ?? null;
        $point = $val === null ? null : whatif_point($payload, $val);
        if (!$point) continue;

        $headline = (float)$point['headline'];
        $diff = ($base === null || $key === 'expected') ? null : round($headline - $base, 2);
        $pct = null;
        if ($diff !== null && $base !== null && abs($base) > 1e-9) {
            $pct = round($diff / abs($base) * 100, 1);
        }

        $rows[$key] = [
            'key'         => $key,
            'label'       => $meta[$key]['label'],
            'blurb'       => $meta[$key]['blurb'],
            'tone'        => $meta[$key]['tone'],
            'lever_value' => $val,
            'lever_text'  => whatif_format_lever($lever, $val),
            'headline'    => $headline,
            'headline_text' => whatif_format_metric($metric, $headline),
            'difference'  => $diff,
            'difference_text' => $diff === null ? '—' : (($diff >= 0 ? '+' : '') . whatif_format_metric($metric, $diff)),
            'pct_change'  => $pct,
            'pct_text'    => $pct === null ? '—' : (($pct >= 0 ? '+' : '') . $pct . '%'),
            'forecast'    => $point['forecast'] ?? [],
            // True when this scenario is the same lever value as EXPECTED —
            // which happens when the user is already at the top or bottom of
            // the slider. The UI says so rather than showing a meaningless
            // "0 difference" row.
            'same_as_expected' => ($key !== 'expected' && $val == $values['expected']),
        ];
    }
    return $rows;
}

/**
 * A one-line, plain-language caveat when the simulation is uninformative,
 * or null when it is fine. Driven by the `response` block the Python engine
 * computes — see response_summary() there.
 */
function whatif_caveat($payload): ?string {
    $r = $payload['response'] ?? null;
    if (!$r) return null;
    // Written in plain language, because this appears in the main reading
    // flow rather than behind the "how was this worked out?" disclosure.
    if (!empty($r['flat'])) {
        return 'Worth knowing: dragging this all the way from one end to the other only changes the result by '
            . whatif_format_metric($payload['metric'], $r['range'])
            . '. That is a real finding, not a glitch — for you, right now, this particular change does not seem to be what moves the outcome. Something else is.';
    }
    if (isset($r['monotonic']) && $r['monotonic'] === false) {
        return 'Worth knowing: the result does not climb perfectly smoothly as you drag. It moves in steps rather than a straight line, so small wobbles between neighbouring points are noise rather than something meaningful.';
    }
    return null;
}

/* ============================================================
   The structured summary sent to the AI layer
   ============================================================ */

/**
 * Condenses a whole simulation down to the small, flat set of numbers the
 * Hugging Face model is allowed to see.
 *
 * This is deliberately NOT the raw payload and definitely not the database:
 * the AI gets a couple of dozen already-computed numbers and nothing else.
 * That keeps the prompt small and cheap, keeps personal data out of a third
 * party's hands, and — most importantly — means there is nothing for the
 * model to do except explain numbers that already exist. It is never asked
 * to produce one.
 */
function whatif_ai_summary($payload, $scenarios, $category): array {
    $lever = $payload['lever'];
    $metric = $payload['metric'];

    $out = [
        'category'            => $category,
        'lever'               => $lever['label'],
        'lever_unit'          => trim($lever['unit'] ?? ''),
        'metric'              => $metric['label'],
        'metric_unit'         => trim(($metric['prefix'] ?? '') . ($metric['suffix'] ?? '')),
        'better_when'         => $metric['direction'] === 'lower_is_better' ? 'lower' : 'higher',
        'current_value'       => $payload['current_value'] ?? null,
        'historical_average'  => $payload['historical_average'] ?? null,
        'model_used'          => $payload['model']['method'] ?? null,
        'model_accuracy_pct'  => $payload['model']['accuracy'] ?? null,
        // How that accuracy figure was earned. The forecasting models are
        // scored by backtesting against THIS user's own past periods; the
        // burnout classifier is scored on a held-out split of the public
        // training dataset. Saying "backtested on your own history" about
        // the second would be a false claim, so the basis travels with the
        // number instead of being assumed.
        'accuracy_basis'      => 'backtest_on_your_own_history',
        'periods_of_history'  => $payload['months_of_history'] ?? ($payload['weeks_of_history'] ?? null),
        'response_is_flat'    => (bool)($payload['response']['flat'] ?? false),
    ];

    foreach (['expected', 'improved', 'risk'] as $k) {
        if (!isset($scenarios[$k])) continue;
        $out[$k . '_input'] = $scenarios[$k]['lever_value'];
        $out[$k . '_prediction'] = $scenarios[$k]['headline'];
        if ($k !== 'expected') {
            $out[$k . '_difference'] = $scenarios[$k]['difference'];
            $out[$k . '_pct_change'] = $scenarios[$k]['pct_change'];
        }
    }
    return $out;
}

/* ============================================================
   BUY vs RENT — deterministic arithmetic, NOT a model prediction
   ============================================================ */

/**
 * Buy-vs-rent comparison.
 *
 * ⚠ READ THIS BEFORE QUOTING IT AS "THE ML MODEL SAID": it isn't one.
 *
 * Nothing in this function is predicted, learned or trained. It is a
 * closed-form loan/rent calculation — the same arithmetic a spreadsheet
 * would do — run over the horizon the user picks. It lives in the finance
 * simulator because it answers a finance what-if question, but it is
 * labelled as a CALCULATION everywhere it is displayed, and it is
 * deliberately kept out of ml/whatif_engine.py so the separation is
 * structural and not just a comment.
 *
 * Why it can't use the existing model: the forecasting model was trained on
 * monthly income/expense series. It has never seen a property price, a loan
 * rate or a rent, so asking it about them would be inventing an answer.
 *
 * Method (standard net-worth comparison at the horizon):
 *   BUY  — pay the deposit, then an EMI plus running costs each month.
 *          Net worth at the horizon = property value - outstanding loan.
 *   RENT — pay rent each month (rising with rent inflation), invest the
 *          deposit, and invest the monthly difference whenever renting is
 *          the cheaper of the two that month.
 *          Net worth at the horizon = the value of that portfolio.
 *
 * Every rate is an ASSUMPTION the user sets and can change. The output is
 * arithmetic on those assumptions, so it is exactly as good as they are.
 *
 * @return array Both sides' cash flows, net worth, the gap, and the first
 *               year (if any) at which buying pulls ahead.
 */
function buy_vs_rent(array $in): array {
    $price       = max(0.0, (float)($in['price'] ?? 0));
    $deposit_pct = min(100.0, max(0.0, (float)($in['deposit_pct'] ?? 20)));
    $rate_pct    = max(0.0, (float)($in['loan_rate_pct'] ?? 8.5));
    $term_years  = max(1, (int)($in['loan_term_years'] ?? 20));
    $horizon     = max(1, (int)($in['horizon_years'] ?? 10));
    $rent        = max(0.0, (float)($in['monthly_rent'] ?? 0));
    $rent_infl   = (float)($in['rent_inflation_pct'] ?? 5);
    $appreciate  = (float)($in['appreciation_pct'] ?? 5);
    $upkeep_pct  = max(0.0, (float)($in['upkeep_pct'] ?? 1.2));   // tax + maintenance, % of price per year
    $invest_pct  = (float)($in['invest_return_pct'] ?? 8);

    $deposit  = $price * $deposit_pct / 100;
    $loan     = $price - $deposit;
    $r        = $rate_pct / 100 / 12;
    $n        = $term_years * 12;

    // EMI — the standard amortising-loan formula. r == 0 is handled so a
    // 0% "interest-free" assumption doesn't divide by zero.
    if ($loan <= 0)      $emi = 0.0;
    elseif ($r <= 1e-12) $emi = $loan / $n;
    else                 $emi = $loan * $r * pow(1 + $r, $n) / (pow(1 + $r, $n) - 1);

    $months        = $horizon * 12;
    $balance       = $loan;
    $home_value    = $price;
    $portfolio     = $deposit;                  // renter invests the deposit instead
    $total_emi     = 0.0;
    $total_interest = 0.0;
    $total_upkeep  = 0.0;
    $total_rent    = 0.0;
    $current_rent  = $rent;

    $m_appreciate = pow(1 + $appreciate / 100, 1 / 12) - 1;
    $m_invest     = pow(1 + $invest_pct / 100, 1 / 12) - 1;
    $m_rent_infl  = pow(1 + $rent_infl / 100, 1 / 12) - 1;

    $yearly = [];
    $breakeven_year = null;

    for ($m = 1; $m <= $months; $m++) {
        // --- buy side ---
        $interest = $balance * $r;
        $principal = max(0.0, $emi - $interest);
        if ($balance > 0) {
            $principal = min($principal, $balance);
            $balance -= $principal;
            $total_interest += $interest;
            $total_emi += $interest + $principal;
        }
        $upkeep = $price * $upkeep_pct / 100 / 12;
        $total_upkeep += $upkeep;
        $home_value *= (1 + $m_appreciate);

        // --- rent side ---
        $total_rent += $current_rent;
        $buy_outflow  = ($balance > 0 || $principal > 0 ? $emi : 0.0) + $upkeep;
        $monthly_gap  = $buy_outflow - $current_rent;
        $portfolio *= (1 + $m_invest);
        if ($monthly_gap > 0) $portfolio += $monthly_gap;   // renter invests what they didn't spend
        $current_rent *= (1 + $m_rent_infl);

        if ($m % 12 === 0) {
            $year = intdiv($m, 12);
            $buy_net  = $home_value - $balance;
            $rent_net = $portfolio;
            $yearly[] = [
                'year' => $year,
                'buy_net_worth' => round($buy_net, 2),
                'rent_net_worth' => round($rent_net, 2),
                'home_value' => round($home_value, 2),
                'loan_balance' => round($balance, 2),
            ];
            if ($breakeven_year === null && $buy_net >= $rent_net) $breakeven_year = $year;
        }
    }

    $buy_net_worth  = $home_value - $balance;
    $rent_net_worth = $portfolio;
    $buy_cash_out   = $deposit + $total_emi + $total_upkeep;

    return [
        'inputs' => [
            'price' => $price, 'deposit_pct' => $deposit_pct, 'deposit' => round($deposit, 2),
            'loan_rate_pct' => $rate_pct, 'loan_term_years' => $term_years,
            'horizon_years' => $horizon, 'monthly_rent' => $rent,
            'rent_inflation_pct' => $rent_infl, 'appreciation_pct' => $appreciate,
            'upkeep_pct' => $upkeep_pct, 'invest_return_pct' => $invest_pct,
        ],
        'buy' => [
            'emi' => round($emi, 2),
            'deposit' => round($deposit, 2),
            'total_emi_paid' => round($total_emi, 2),
            'total_interest' => round($total_interest, 2),
            'total_upkeep' => round($total_upkeep, 2),
            'total_cash_out' => round($buy_cash_out, 2),
            'home_value' => round($home_value, 2),
            'loan_balance' => round($balance, 2),
            'net_worth' => round($buy_net_worth, 2),
        ],
        'rent' => [
            'first_month_rent' => round($rent, 2),
            'last_month_rent' => round($current_rent, 2),
            'total_rent_paid' => round($total_rent, 2),
            'total_cash_out' => round($total_rent, 2),
            'invested_portfolio' => round($portfolio, 2),
            'net_worth' => round($rent_net_worth, 2),
        ],
        'verdict' => [
            'better' => $buy_net_worth > $rent_net_worth ? 'buy' : ($rent_net_worth > $buy_net_worth ? 'rent' : 'tie'),
            'gap' => round(abs($buy_net_worth - $rent_net_worth), 2),
            'breakeven_year' => $breakeven_year,
        ],
        'yearly' => $yearly,
        'is_calculation_not_prediction' => true,
    ];
}
