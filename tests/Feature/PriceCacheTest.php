<?php

namespace Tests\Feature;

use App\Enums\Symbol;
use App\Support\PriceCache;
use App\Support\PriceQuote;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class PriceCacheTest extends FeatureTestCase
{
    #[Test]
    public function put_then_get_round_trips_the_quote(): void
    {
        $quotedAt = CarbonImmutable::now();
        $priceCache = app(PriceCache::class);

        $priceCache->put(new PriceQuote(Symbol::XauUsd, '2001.00000000', $quotedAt));

        $cached = $priceCache->get(Symbol::XauUsd);

        $this->assertSame('2001.00000000', $cached['price']);
        $this->assertSame($quotedAt->toIso8601String(), $cached['quoted_at']);
    }

    #[Test]
    public function get_returns_null_when_nothing_has_been_cached(): void
    {
        $this->assertNull(app(PriceCache::class)->get(Symbol::XauUsd));
    }

    #[Test]
    public function a_cache_write_failure_is_swallowed_not_propagated(): void
    {
        Log::spy();

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('put')->once()->andThrow(new RuntimeException('cache store unavailable'));

        $priceCache = new PriceCache($cache);

        $priceCache->put(new PriceQuote(Symbol::XauUsd, '2001.00000000', CarbonImmutable::now()));

        Log::shouldHaveReceived('warning')->with('failed to cache current price', Mockery::any())->once();
    }
}
