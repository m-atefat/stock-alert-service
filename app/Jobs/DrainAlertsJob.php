<?php

namespace App\Jobs;

use App\Enums\Symbol;
use App\Jobs\Concerns\ClaimsOnePass;
use App\Services\AlertClaimer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DrainAlertsJob implements ShouldQueue
{
    use ClaimsOnePass, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [1, 5, 15];

    public function __construct(
        public readonly Symbol $symbol,
        public readonly string $price,
        public readonly string $tickId,
        public readonly int $pass,
    ) {
        $this->onConnection('rabbitmq');
    }

    /**
     * @throws Throwable
     */
    public function handle(AlertClaimer $claimer): void
    {
        $this->claimOnePass($claimer, $this->symbol, $this->price, $this->tickId, currentPass: $this->pass);
    }
}
