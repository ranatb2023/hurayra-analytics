<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Direct WooCommerce sync (read only)
    |--------------------------------------------------------------------------
    |
    | When WC_DB_HOST is set, `php artisan woo:sync` (scheduled every 15
    | minutes) reads new and changed orders and subscriptions straight from the
    | store's database, using the same queries as database/export/*.sql. The
    | connection itself is `woocommerce` in config/database.php. CSV upload
    | keeps working as a fallback.
    |
    */

    'enabled' => filled(env('WC_DB_HOST')) && filled(env('WC_DB_NAME')),

    'table_prefix' => env('WC_TABLE_PREFIX', 'wp_'),

    // Re-read rows updated this many seconds before the last sync, so a row
    // committed while the previous sync was running is not missed.
    'overlap_seconds' => 300,

];
