<?php

return [

    /*
    |--------------------------------------------------------------------------
    | "No orders" alert
    |--------------------------------------------------------------------------
    |
    | Checked hourly. When no new shop_order has arrived for `hours` during UK
    | trading hours, an alert goes out — once per incident: the next one only
    | fires after a new order has arrived and the store has gone quiet again.
    |
    | Trading hours are [from_hour, until_hour) in Europe/London, so a quiet
    | night does not page anyone at 3am.
    |
    */

    'no_orders' => [
        'hours' => (int) env('ALERT_NO_ORDERS_HOURS', 12),
        'from_hour' => (int) env('ALERT_NO_ORDERS_FROM_HOUR', 8),
        'until_hour' => (int) env('ALERT_NO_ORDERS_UNTIL_HOUR', 23),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | Email first: a comma-separated list of recipients. Slack is optional —
    | an incoming-webhook URL. Leave either blank to skip that channel.
    |
    */

    'mail_to' => env('ALERT_EMAIL_TO'),

    'slack_webhook' => env('ALERT_SLACK_WEBHOOK'),

];
