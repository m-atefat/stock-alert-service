<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Run by the `scheduler` supervisor program via `schedule:work`, which
| evaluates due tasks every second rather than every minute — that is what
| makes the sub-minute price cadence below possible at all.
|
| Every task uses withoutOverlapping() with a short expiry. Laravel's lock
| granularity is minutes and the default expiry is 24 hours: a container
| killed mid-run would otherwise leave a lock behind that only self-heals a
| day later, which is its own outage.
|
*/

// Opt-in, and off by default. GoldAPI.io's free tier allows roughly 100
// requests per MONTH — a one-second cadence needs 2.6 million, and even one
// call every 15 minutes needs ~2,900. There is no cadence that both keeps the
// price fresh and fits the free tier, so polling is enabled explicitly by
// whoever has a plan that can sustain it. `php artisan price:tick` always
// works on demand; see the README's "Price feed" section.
if (config('prices.tick.enabled')) {
    Schedule::command('price:tick')
        ->everyTenSeconds()
        ->withoutOverlapping(1)
        ->runInBackground();
}

// The catch-all sweep. Finds active alerts already crossed by the last known
// price and claims them. Load-bearing, not a backstop: it is the only recovery
// path for a claim lost because Redis was unavailable, and for the gap between
// the ZSET claim and the database write that records it.
Schedule::command('alerts:reconcile')
    ->everyMinute()
    ->withoutOverlapping(2);

// Recovery for a notification whose message was lost after the claim — the one
// failure alerts:reconcile cannot see, because it only scans active alerts.
Schedule::command('notifications:redispatch-pending')
    ->everyMinute()
    ->withoutOverlapping(2);

// Retention, not a hot path: once a day, off-peak. Only removes triggered
// alerts whose notification already reached a terminal state.
Schedule::command('alerts:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping();
