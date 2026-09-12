<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\Symbol;
use App\Models\PriceAlert;
use App\Redis\AlertIndex;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;

class RedisResilienceTest extends FeatureTestCase
{
    #[Test]
    public function restores_index_parity_from_the_database_via_alerts_rebuild(): void
    {
        PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2500.00000000',
            'direction' => AlertDirection::Above,
        ]);
        PriceAlert::factory()->below()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '1800.00000000',
        ]);

        $index = app(AlertIndex::class);
        $this->assertTrue($index->isEmpty(Symbol::XauUsd));

        $this->artisan('alerts:rebuild')->assertExitCode(0);

        $this->assertSame(1, $index->count(Symbol::XauUsd, AlertDirection::Above));
        $this->assertSame(1, $index->count(Symbol::XauUsd, AlertDirection::Below));
    }

    #[Test]
    public function removes_a_stale_member_that_no_longer_has_an_active_alert(): void
    {
        $index = app(AlertIndex::class);

        Redis::connection()->zadd($index->aboveKey(Symbol::XauUsd), 999900000000, 424242);

        $this->assertTrue($index->isIndexed(Symbol::XauUsd, AlertDirection::Above, 424242));

        $this->artisan('alerts:rebuild')->assertExitCode(0);

        $this->assertFalse($index->isIndexed(Symbol::XauUsd, AlertDirection::Above, 424242));
    }
}
