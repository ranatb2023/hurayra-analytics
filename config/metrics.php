<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gross margin
    |--------------------------------------------------------------------------
    |
    | Product gross margin as a percentage of NET revenue (ex VAT, ex shipping).
    | Lifetime value is a revenue number until this is set: whether a subscriber
    | worth GBP 32 net is profitable depends entirely on what the food cost.
    |
    | Leave null while it is unknown. Everything derived from it — contribution
    | per subscriber, the CAC ceiling, payback — then reports as "not set"
    | rather than quietly assuming a figure nobody agreed.
    |
    */

    'gross_margin_pct' => env('METRICS_GROSS_MARGIN_PCT') === null
        ? null
        : (float) env('METRICS_GROSS_MARGIN_PCT'),

    /*
    |--------------------------------------------------------------------------
    | Customer acquisition cost
    |--------------------------------------------------------------------------
    |
    | Blended CAC per acquired subscriber. Nothing in a WooCommerce export
    | carries ad spend, so this has to come from the ad platforms. With it, the
    | acquisition table can show payback; without it, LTV has no counterweight.
    |
    */

    'cac' => env('METRICS_CAC') === null ? null : (float) env('METRICS_CAC'),

    /*
    |--------------------------------------------------------------------------
    | Segment reporting
    |--------------------------------------------------------------------------
    |
    | Below this many subscriptions a segment's rate is too noisy to act on. At
    | n = 13 a 69% repeat rate carries a 95% interval of roughly +/- 25 points —
    | it could be anywhere from 44% to 94%. Rows under this are flagged rather
    | than hidden, so the sample size is visible instead of implied.
    |
    */

    'segment_min_reliable_n' => 30,

];
