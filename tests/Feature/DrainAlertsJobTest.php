<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\Symbol;
use App\Jobs\DrainAlertsJob;
use App\Models\PriceAlert;
use App\Redis\AlertIndex;
use App\Services\AlertClaimer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

class DrainAlertsJobTest extends FeatureTestCase
{
    #[Test]
    public function a_full_batch_re_dispatches_itself_onto_prices_drain_for_the_next_pass(): void
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

        (new DrainAlertsJob(Symbol::XauUsd, '2001.00000000', 'tick-1', pass: 2))->handle(app(AlertClaimer::class));

        Queue::assertPushedOn('prices.drain', DrainAlertsJob::class, function (DrainAlertsJob $job) {
            return $job->symbol === Symbol::XauUsd
                && $job->tickId === 'tick-1'
                && $job->pass === 3;
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

        (new DrainAlertsJob(Symbol::XauUsd, '2001.00000000', 'tick-1', pass: 2))->handle(app(AlertClaimer::class));

        Queue::assertNotPushed(DrainAlertsJob::class);
    }

    #[Test]
    public function it_stops_and_logs_an_error_at_max_drain_passes_instead_of_re_dispatching(): void
    {
        Queue::fake();
        Log::spy();
        config(['prices.match_batch_size' => 1, 'prices.max_drain_passes' => 3]);

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

        (new DrainAlertsJob(Symbol::XauUsd, '2001.00000000', 'tick-1', pass: 3))->handle(app(AlertClaimer::class));

        Queue::assertNotPushed(DrainAlertsJob::class);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === 'match drain exceeded max passes — stopping'
                && $context['tick'] === 'tick-1'
                && $context['pass'] === 3,
        );
    }
}
