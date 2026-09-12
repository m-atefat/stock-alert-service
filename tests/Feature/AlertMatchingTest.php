<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\Symbol;
use App\Models\PriceAlert;
use App\Redis\AlertIndex;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class AlertMatchingTest extends FeatureTestCase
{
    /**
     * @throws Throwable
     */
    #[Test]
    public function claims_a_crossed_alert_exactly_once_no_matter_how_many_times_matching_is_invoked(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');

        $claimed = [];
        for ($i = 0; $i < 50; $i++) {
            $claimed = array_merge($claimed, $index->matchAbove(Symbol::XauUsd, '2001.00000000', 10));
        }

        $this->assertSame([$alert->id], $claimed);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function never_fires_an_alert_twice_even_at_a_higher_price_on_a_later_tick(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');

        $first = $index->matchAbove(Symbol::XauUsd, '2000.00000000', 100);
        $second = $index->matchAbove(Symbol::XauUsd, '3000.00000000', 100);

        $this->assertSame([$alert->id], $first);
        $this->assertSame([], $second);
    }

    #[Test]
    public function fires_when_the_target_price_is_exactly_reached_not_just_crossed(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');

        $this->assertSame([$alert->id], $index->matchAbove(Symbol::XauUsd, '2000.00000000', 10));
    }

    #[Test]
    public function does_not_fire_when_the_price_has_not_reached_the_target(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000001',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000001');

        $this->assertSame([], $index->matchAbove(Symbol::XauUsd, '2000.00000000', 10));
    }

    #[Test]
    public function fires_a_below_alert_once_the_price_drops_to_or_below_the_target(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '1900.00000000',
            'direction' => AlertDirection::Below,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Below, $alert->id, '1900.00000000');

        $this->assertSame([], $index->matchBelow(Symbol::XauUsd, '1950.00000000', 10));
        $this->assertSame([$alert->id], $index->matchBelow(Symbol::XauUsd, '1900.00000000', 10));
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function recovers_from_the_script_cache_being_flushed_mid_run(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);

        $index->matchAbove(Symbol::XauUsd, '1000.00000000', 10);
        Redis::connection()->script('flush');

        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');

        $this->assertSame([$alert->id], $index->matchAbove(Symbol::XauUsd, '2001.00000000', 10));
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function does_not_corrupt_decimal_precision_through_the_scaled_zset_score(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '1999.99999999',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '1999.99999999');

        $this->assertSame([], $index->matchAbove(Symbol::XauUsd, '1999.99999998', 10));
        $this->assertSame([$alert->id], $index->matchAbove(Symbol::XauUsd, '1999.99999999', 10));
    }
}
