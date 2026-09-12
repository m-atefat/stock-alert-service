<?php

namespace App\Console\Commands;

use App\Enums\AlertDirection;
use App\Enums\Symbol;
use App\Models\PriceAlert;
use App\Redis\AlertIndex;
use App\Services\AlertClaimer;
use App\Support\PriceCache;
use Illuminate\Console\Command;
use Throwable;

class ReconcileAlertsCommand extends Command
{
    protected $signature = 'alerts:reconcile {--grace=10 : seconds an alert must have existed before this will touch it}';

    protected $description = 'Re-claim active alerts already crossed by the last known price.';

    public function handle(AlertIndex $index, AlertClaimer $claimer, PriceCache $priceCache): int
    {
        return $this->reconcile(Symbol::XauUsd, $index, $claimer, $priceCache) ? self::SUCCESS : self::FAILURE;
    }

    private function reconcile(Symbol $symbol, AlertIndex $index, AlertClaimer $claimer, PriceCache $priceCache): bool
    {
        $cached = $priceCache->get($symbol);

        if ($cached === null) {
            $this->warn("[{$symbol->value}] no known price cached, nothing to reconcile against");

            return true;
        }

        $price = (string) $cached['price'];
        $cutoff = now()->subSeconds((int) $this->option('grace'));
        $reconciled = 0;

        try {
            PriceAlert::query()
                ->active()
                ->where('symbol', $symbol->value)
                ->where('created_at', '<=', $cutoff)
                ->where(function ($query) use ($price) {
                    $query->where(fn ($q) => $q->where('direction', AlertDirection::Above)->where('target_price', '<=', $price))
                        ->orWhere(fn ($q) => $q->where('direction', AlertDirection::Below)->where('target_price', '>=', $price));
                })
                ->select(['id', 'direction'])
                ->chunkById(1000, function ($crossed) use (&$reconciled, $index, $claimer, $symbol, $price) {
                    $index->removeMany($symbol, $crossed->pluck('direction', 'id')->all());

                    $reconciled += count($claimer->recordBatch($crossed->pluck('id')->all(), $price));
                });
        } catch (Throwable $e) {
            $this->error("[{$symbol->value}] reconcile failed: {$e->getMessage()}");

            return false;
        }

        $this->info("[{$symbol->value}] reconciled {$reconciled} alert(s) against price {$price}");

        return true;
    }
}
