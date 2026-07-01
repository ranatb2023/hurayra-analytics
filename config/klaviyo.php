<?php

return [
    // Base URL for the Klaviyo API.
    'base_url' => env('KLAVIYO_BASE_URL', 'https://a.klaviyo.com'),

    // Private API key — Authorization: Klaviyo-API-Key {key}.
    // Required scopes: campaigns:read, flows:read, metrics:read, lists:read.
    'api_key' => env('KLAVIYO_API_KEY'),

    // The newsletter list whose subscriber count powers the Subscribers tile.
    'list_id' => env('KLAVIYO_LIST_ID'),

    // Pinned API revision. Klaviyo deprecates revisions on a ~yearly cycle —
    // bump this value (and re-test) when notified, it's surfaced in the UI.
    'revision' => env('KLAVIYO_REVISION', '2025-01-15'),

    // The conversion event used for Revenue/Conversions (WooCommerce → "Placed Order").
    'conversion_metric_name' => env('KLAVIYO_CONVERSION_METRIC', 'Placed Order'),

    // WooCommerce Subscriptions events — used to attribute subscription purchases
    // to campaigns. Leave blank if your store doesn't fire them.
    'subscription_created_metric' => env('KLAVIYO_SUBSCRIPTION_METRIC', 'WC Subscription Created'),
    'subscription_renewal_metric' => env('KLAVIYO_RENEWAL_METRIC', 'WC Subscription Renewal'),

    // Account/report currency for the Revenue tile.
    'currency' => env('KLAVIYO_CURRENCY', 'GBP'),

    // Timezone used to build report timeframes so buckets line up with the
    // Klaviyo UI (which uses the account timezone). Leave blank to auto-detect
    // from the Klaviyo account (cached); set explicitly to override.
    'timezone' => env('KLAVIYO_TIMEZONE'),

    // Retry policy for 429 / 5xx responses.
    'retries' => 4,
];
