<?php

namespace App\Console\Commands;

use App\Enums\AlertDirection;
use App\Enums\Symbol;
use App\Models\PriceAlert;
use App\Redis\AlertIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class RebuildAlertIndexCommand extends Command
{
    protected $signature = 'alerts:rebuild {--chunk=1000}';

    protected $description = 'Replay active alerts from the database into the Redis index, removing stale members.';

    public function handle(AlertIndex $index): int
    {
        $lock = Cache::lock('alerts:rebuild:lock', 900);

        if (! $lock->get()) {
            $this->warn('another rebuild is already in progress');

            return self::SUCCESS;
        }

        try {
            $startedAt = now();
            $count = 0;

            PriceAlert::query()->active()->chunkById((int) $this->option('chunk'), function ($alerts) use ($index, &$count) {
                $this->addManyGrouped($index, $alerts, ':rebuild');
                $count += $alerts->count();
            });

            $this->swapInRebuiltKeys($index);

            $caughtUp = 0;

            PriceAlert::query()->active()->where('created_at', '>=', $startedAt)
                ->chunkById((int) $this->option('chunk'), function ($alerts) use ($index, &$caughtUp) {
                    $this->addManyGrouped($index, $alerts);
                    $caughtUp += $alerts->count();
                });

            $this->info("rebuilt index with {$count} active alert(s) ({$caughtUp} caught up from mid-rebuild writes)");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, PriceAlert>  $alerts
     */
    private function addManyGrouped(AlertIndex $index, $alerts, string $keySuffix = ''): void
    {
        $grouped = $alerts->groupBy(fn (PriceAlert $alert) => $alert->symbol->value.'|'.$alert->direction->value);

        foreach ($grouped as $group => $alertsForKey) {
            [$symbolValue, $directionValue] = explode('|', $group);

            $index->addMany(
                Symbol::from($symbolValue),
                AlertDirection::from((int) $directionValue),
                $alertsForKey->pluck('target_price', 'id')->all(),
                $keySuffix,
            );
        }
    }

    private function swapInRebuiltKeys(AlertIndex $index): void
    {
        $redis = Redis::connection();

        foreach (Symbol::cases() as $symbol) {
            foreach (AlertDirection::cases() as $direction) {
                $liveKey = $index->keyFor($symbol, $direction);
                $tempKey = $liveKey.':rebuild';

                if ($redis->exists($tempKey)) {
                    $redis->rename($tempKey, $liveKey);
                } else {
                    $redis->del($liveKey);
                }
            }
        }
    }
}
