<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\Symbol;
use App\Jobs\PriceTicked;
use App\Models\PriceAlert;
use App\Redis\AlertIndex;
use App\Services\PriceIngestor;
use App\Support\PriceCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

class GoldApiIntegrationTest extends FeatureTestCase
{
    #[Test]
    public function dispatches_price_ticked_through_the_real_provider(): void
    {
        Queue::fake();
        $this->fakeGoldApi('2001.00000000');

        $result = app(PriceIngestor::class)->tick(Symbol::XauUsd);

        $this->assertNull($result['skipped_reason']);
        $this->assertSame('2001.00000000', $result['price']);
        Queue::assertPushed(fn (PriceTicked $job) => $job->symbol === Symbol::XauUsd && $job->price === '2001.00000000');
    }

    #[Test]
    public function an_old_quote_is_still_cached_and_still_dispatches_price_ticked(): void
    {
        Queue::fake();
        $quotedAt = now()->subMinutes(10)->toImmutable();
        $this->fakeGoldApi('2001.00000000', $quotedAt);

        $result = app(PriceIngestor::class)->tick(Symbol::XauUsd);

        $this->assertNull($result['skipped_reason']);
        $this->assertSame('2001.00000000', $result['price']);

        $cached = app(PriceCache::class)->get(Symbol::XauUsd);
        $this->assertSame('2001.00000000', $cached['price']);
        $this->assertSame($quotedAt->toIso8601String(), $cached['quoted_at']);

        Queue::assertPushed(fn (PriceTicked $job) => $job->symbol === Symbol::XauUsd && $job->price === '2001.00000000');
    }

    #[Test]
    public function skips_the_tick_and_leaves_alerts_untouched_when_the_api_is_down(): void
    {
        Queue::fake();
        Http::fake(['*goldapi.io/*' => Http::response([], 500)]);

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');

        $result = app(PriceIngestor::class)->tick(Symbol::XauUsd);

        $this->assertSame('fetch_failed', $result['skipped_reason']);
        $this->assertSame(AlertStatus::Active, $alert->fresh()->status);
        $this->assertSame(1, $index->count(Symbol::XauUsd, AlertDirection::Above));
        Queue::assertNotPushed(PriceTicked::class);
    }

    #[Test]
    public function logs_a_fetch_failure_only_on_the_state_transition_not_every_tick(): void
    {
        Queue::fake();
        Log::spy();
        Http::fake(['*goldapi.io/*' => Http::response([], 500)]);

        app(PriceIngestor::class)->tick(Symbol::XauUsd);
        app(PriceIngestor::class)->tick(Symbol::XauUsd);
        app(PriceIngestor::class)->tick(Symbol::XauUsd);

        Log::shouldHaveReceived('warning')->with('gold price fetch failed', \Mockery::any())->once();
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function logs_a_recovery_once_the_provider_starts_succeeding_again(): void
    {
        Queue::fake();
        Log::spy();

        $attempt = 0;
        $at = now();

        Http::fake(function () use (&$attempt, $at) {
            $attempt++;

            if ($attempt === 1) {
                return Http::response([], 500);
            }

            $price = '2001.00000000';

            return Http::response([
                'timestamp' => $at->getTimestamp(),
                'datetime' => $at->toIso8601String(),
                'metal' => 'XAU', 'currency' => 'USD',
                'exchange' => 'FOREXCOM', 'symbol' => 'FOREXCOM:XAUUSD',
                'prev_close_price' => $price, 'open_price' => $price,
                'low_price' => $price, 'high_price' => $price,
                'open_time' => $at->getTimestamp(),
                'price' => $price, 'unit' => 'troy_ounce',
                'change' => 0, 'change_percent' => 0,
                'ask' => $price, 'bid' => $price,
                'price_per_unit' => ['troy_ounce' => $price, 'gram' => $price, 'kilogram' => $price],
                'melt_price_per_gram' => ['24k' => $price, '22k' => $price, '18k' => $price],
                'currency_info' => ['code' => 'USD', 'name' => 'United States Dollar', 'symbol' => 'US$'],
            ], 200);
        });

        app(PriceIngestor::class)->tick(Symbol::XauUsd);
        app(PriceIngestor::class)->tick(Symbol::XauUsd);
        app(PriceIngestor::class)->tick(Symbol::XauUsd);

        Log::shouldHaveReceived('info')->with('gold price fetch recovered', \Mockery::any())->once();
    }

    #[Test]
    public function splits_the_symbol_into_metal_and_currency_for_the_request_url(): void
    {
        Queue::fake();
        $this->fakeGoldApi('2001.00000000');

        app(PriceIngestor::class)->tick(Symbol::XauUsd);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/price/XAU/USD'));
    }
}
