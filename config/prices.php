<?php

return [

    'match_batch_size' => (int) env('PRICE_MATCH_BATCH_SIZE', 1000),

    'max_drain_passes' => (int) env('PRICE_MAX_DRAIN_PASSES', 5000),

    'provider' => env('PRICE_PROVIDER', 'goldapi'),

    'retention_days' => (int) env('ALERT_RETENTION_DAYS', 30),

    // Price polling is opt-in and off by default — see routes/console.php for
    // why (the provider's free tier cannot sustain any useful cadence).
    // `php artisan price:tick` always works on demand regardless of this.
    'tick' => [
        'enabled' => (bool) env('PRICE_TICK_ENABLED', false),
    ],

    'notifications' => [
        'redispatch_after' => (int) env('NOTIFICATION_REDISPATCH_AFTER', 60),
    ],

];
