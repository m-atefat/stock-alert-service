<?php

namespace App\Services;

use App\Contracts\PriceProvider;
use App\Enums\Symbol;
use App\Exceptions\PriceProviderException;
use App\Jobs\PriceTicked;
use App\Support\PriceCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

readonly class PriceIngestor
{
    public function __construct(
        private PriceProvider $provider,
        private PriceCache $priceCache,
    ) {}

    /**
     * @return array{price: ?string, skipped_reason: ?string, tick_id: ?string}
     */
    public function tick(Symbol $symbol): array
    {
        try {
            $quote = $this->provider->fetch($symbol);
            $this->clearFetchFailureFlag($symbol);
        } catch (PriceProviderException $e) {
            $this->logFetchFailure($symbol, $e);

            return $this->skip();
        }

        $this->priceCache->put($quote);

        $tickId = (string) Str::uuid();

        PriceTicked::dispatch($symbol, $quote->price, $tickId)->onQueue("price.{$symbol->value}");

        return ['price' => $quote->price, 'skipped_reason' => null, 'tick_id' => $tickId];
    }

    private function fetchFailureFlagKey(Symbol $symbol): string
    {
        return "price:fetch:failing:{$symbol->value}";
    }

    private function logFetchFailure(Symbol $symbol, PriceProviderException $e): void
    {
        $key = $this->fetchFailureFlagKey($symbol);

        if (! cache($key)) {
            Log::warning('gold price fetch failed', ['symbol' => $symbol->value, 'error' => $e->getMessage()]);
            cache([$key => true], now()->addMinutes(10));
        }
    }

    private function clearFetchFailureFlag(Symbol $symbol): void
    {
        $key = $this->fetchFailureFlagKey($symbol);

        if (cache($key)) {
            cache()->forget($key);
            Log::info('gold price fetch recovered', ['symbol' => $symbol->value]);
        }
    }

    /**
     * @return array{price: ?string, skipped_reason: string, tick_id: null}
     */
    private function skip(): array
    {
        return ['price' => null, 'skipped_reason' => 'fetch_failed', 'tick_id' => null];
    }
}
