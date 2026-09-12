<?php

namespace App\Console\Commands;

use App\Contracts\PriceProvider;
use App\Enums\Symbol;
use App\Services\PriceIngestor;
use App\Support\PriceQuote;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;

class FetchPricesCommand extends Command
{
    protected $signature = 'price:tick
        {--price= : skip the provider and publish this price instead}';

    protected $description = 'Fetch the current price and claim every alert it crosses.';

    /**
     * @throws BindingResolutionException
     */
    public function handle(): int
    {
        $symbol = Symbol::XauUsd;

        if ($this->option('price') !== null) {
            $this->useFixedPrice((string) $this->option('price'));
        }

        // Resolved here rather than injected, so the binding swapped above is
        // the one this run sees.
        $result = $this->laravel->make(PriceIngestor::class)->tick($symbol);

        if ($result['skipped_reason'] !== null) {
            $this->warn("[{$symbol->value}] tick skipped ({$result['skipped_reason']})");

            return self::SUCCESS;
        }

        $this->info("[{$symbol->value}] price={$result['price']} tick={$result['tick_id']} dispatched=PriceTicked");

        return self::SUCCESS;
    }

    /**
     * Swap the provider for one that returns a fixed quote, so the pipeline can
     * be exercised without the upstream API — useful for a demo, and the only
     * way to drive it once the provider's free-tier quota is spent.
     *
     * The override lives here in the CLI layer on purpose: PriceIngestor keeps
     * no test hook, and everything downstream of the fetch (cache, RabbitMQ,
     * matching, claim, delivery) runs exactly as it does in production.
     */
    private function useFixedPrice(string $price): void
    {
        $this->laravel->instance(PriceProvider::class, new class($price) implements PriceProvider
        {
            public function __construct(private readonly string $price) {}

            public function fetch(Symbol $symbol): PriceQuote
            {
                return new PriceQuote($symbol, $this->price, CarbonImmutable::now());
            }
        });

        // PriceIngestor is a singleton holding the real provider; drop the
        // cached instance so it is rebuilt with the binding above.
        $this->laravel->forgetInstance(PriceIngestor::class);
    }
}
