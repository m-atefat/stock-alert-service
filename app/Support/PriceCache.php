<?php

namespace App\Support;

use App\Enums\Symbol;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

final readonly class PriceCache
{
    public function __construct(
        private Cache $cache,
    ) {}

    public static function key(Symbol $symbol): string
    {
        return "price:current:{$symbol->value}";
    }

    public function put(PriceQuote $quote): void
    {
        try {
            $this->cache->put(
                self::key($quote->symbol),
                ['price' => $quote->price, 'quoted_at' => $quote->quotedAt->toIso8601String()],
                now()->addMinute(),
            );
        } catch (Throwable $e) {
            Log::warning('failed to cache current price', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{price: string, quoted_at: string}|null
     *
     * @throws InvalidArgumentException
     */
    public function get(Symbol $symbol): ?array
    {
        return $this->cache->get(self::key($symbol));
    }
}
