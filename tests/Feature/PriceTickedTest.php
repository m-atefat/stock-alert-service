<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\Symbol;
use App\Jobs\DrainAlertsJob;
use App\Jobs\PriceTicked;
use App\Jobs\SendNotificationJob;
use App\Models\AlertNotification;
use App\Models\PriceAlert;
use App\Redis\AlertIndex;
use App\Services\AlertClaimer;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

class PriceTickedTest extends FeatureTestCase
{
    #[Test]
    public function claims_crossed_alerts_writes_one_mail_notification_and_dispatches_one_job(): void
    {
        Queue::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');

        (new PriceTicked(Symbol::XauUsd, '2001.00000000', 'tick-1'))->handle(app(AlertClaimer::class));

        $this->assertSame(AlertStatus::Triggered, $alert->fresh()->status);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->count());
        Queue::assertPushed(SendNotificationJob::class, 1);
        $this->assertTrue($index->isEmpty(Symbol::XauUsd));
    }

    #[Test]
    public function a_redelivery_claims_nothing_new_and_creates_no_duplicate_row(): void
    {
        Queue::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');

        $claimer = app(AlertClaimer::class);
        (new PriceTicked(Symbol::XauUsd, '2001.00000000', 'tick-1'))->handle($claimer);
        (new PriceTicked(Symbol::XauUsd, '2001.00000000', 'tick-1'))->handle($claimer);

        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->count());
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    #[Test]
    public function claims_alerts_on_both_sides_of_the_book_in_one_tick(): void
    {
        Queue::fake();

        $above = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $below = PriceAlert::factory()->below()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2500.00000000',
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $above->id, '2000.00000000');
        $index->add(Symbol::XauUsd, AlertDirection::Below, $below->id, '2500.00000000');

        (new PriceTicked(Symbol::XauUsd, '2001.00000000', 'tick-1'))->handle(app(AlertClaimer::class));

        $this->assertSame(AlertStatus::Triggered, $above->fresh()->status);
        $this->assertSame(AlertStatus::Triggered, $below->fresh()->status);
    }

    #[Test]
    public function a_full_batch_dispatches_a_continuation_onto_prices_drain_starting_at_pass_two(): void
    {
        Queue::fake();
        config(['prices.match_batch_size' => 1]);

        $first = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $second = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.50000000',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $first->id, '2000.00000000');
        $index->add(Symbol::XauUsd, AlertDirection::Above, $second->id, '2000.50000000');

        (new PriceTicked(Symbol::XauUsd, '2001.00000000', 'tick-1'))->handle(app(AlertClaimer::class));

        Queue::assertPushedOn('prices.drain', DrainAlertsJob::class, function (DrainAlertsJob $job) {
            return $job->symbol === Symbol::XauUsd
                && $job->tickId === 'tick-1'
                && $job->pass === 2;
        });
    }

    #[Test]
    public function a_partial_batch_dispatches_nothing_further(): void
    {
        Queue::fake();
        config(['prices.match_batch_size' => 1000]);

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $index = app(AlertIndex::class);
        $index->add(Symbol::XauUsd, AlertDirection::Above, $alert->id, '2000.00000000');

        (new PriceTicked(Symbol::XauUsd, '2001.00000000', 'tick-1'))->handle(app(AlertClaimer::class));

        Queue::assertNotPushed(DrainAlertsJob::class);
    }
}
