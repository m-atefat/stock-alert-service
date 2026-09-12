<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\Symbol;
use App\Models\AlertNotification;
use App\Models\PriceAlert;
use App\Redis\AlertIndex;
use App\Support\PriceCache;
use App\Support\PriceQuote;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

class ReconcileAlertsTest extends FeatureTestCase
{
    private function cacheCurrentPrice(Symbol $symbol, string $price): void
    {
        app(PriceCache::class)->put(new PriceQuote($symbol, $price, now()->toImmutable()));
    }

    #[Test]
    public function reconciles_an_active_alert_already_crossed_by_the_last_known_price(): void
    {
        Queue::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
            'created_at' => now()->subMinute(),
        ]);

        $this->cacheCurrentPrice(Symbol::XauUsd, '2001.00000000');

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');

        $this->artisan('alerts:reconcile')->assertExitCode(0);

        $this->assertSame(AlertStatus::Triggered, $alert->fresh()->status);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->count());
        $this->assertTrue($index->isEmpty(Symbol::XauUsd));
    }

    #[Test]
    public function leaves_a_recently_created_alert_alone_within_the_grace_window(): void
    {
        Queue::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $this->cacheCurrentPrice(Symbol::XauUsd, '2001.00000000');

        $this->artisan('alerts:reconcile')->assertExitCode(0);

        $this->assertSame(AlertStatus::Active, $alert->fresh()->status);
    }

    #[Test]
    public function recovers_an_alert_left_active_after_its_index_claim_but_before_the_db_write(): void
    {
        Queue::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
            'created_at' => now()->subMinute(),
        ]);

        $this->cacheCurrentPrice(Symbol::XauUsd, '2001.00000000');

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');
        $index->matchAbove(Symbol::XauUsd, '2001.00000000', 10);

        $this->assertTrue($index->isEmpty(Symbol::XauUsd));
        $this->assertSame(AlertStatus::Active, $alert->fresh()->status);

        $this->artisan('alerts:reconcile')->assertExitCode(0);

        $this->assertSame(AlertStatus::Triggered, $alert->fresh()->status);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->count());
    }
}
