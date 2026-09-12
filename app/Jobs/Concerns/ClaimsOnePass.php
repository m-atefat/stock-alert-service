<?php

namespace App\Jobs\Concerns;

use App\Enums\Symbol;
use App\Jobs\DrainAlertsJob;
use App\Services\AlertClaimer;
use Illuminate\Support\Facades\Log;
use Throwable;

trait ClaimsOnePass
{
    /**
     * @throws Throwable
     */
    private function claimOnePass(AlertClaimer $claimer, Symbol $symbol, string $price, string $tickId, int $currentPass): void
    {
        $result = $claimer->claimOnePass($symbol, $price);

        if (! $result['more']) {
            return;
        }

        if ($currentPass >= (int) config('prices.max_drain_passes')) {
            Log::error('match drain exceeded max passes — stopping', [
                'tick' => $tickId,
                'symbol' => $symbol->value,
                'pass' => $currentPass,
            ]);

            return;
        }

        DrainAlertsJob::dispatch($symbol, $price, $tickId, $currentPass + 1)
            ->onQueue('prices.drain');
    }
}
