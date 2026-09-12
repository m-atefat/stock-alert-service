<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\NotificationStatus;
use App\Enums\Symbol;
use App\Jobs\SendNotificationJob;
use App\Models\AlertNotification;
use App\Models\PriceAlert;
use App\Services\AlertClaimer;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

class AlertClaimerTest extends FeatureTestCase
{
    private function alert(): PriceAlert
    {
        return PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function records_one_mail_notification_and_dispatches_one_job(): void
    {
        Queue::fake();

        $alert = $this->alert();

        $claimed = app(AlertClaimer::class)->recordAndDispatch($alert->id, '2001.00000000');

        $this->assertTrue($claimed);
        $this->assertSame(AlertStatus::Triggered, $alert->fresh()->status);
        $this->assertSame('2001.00000000', $alert->fresh()->triggered_price);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->count());
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function does_not_re_trigger_or_duplicate_notifications_for_an_already_triggered_alert(): void
    {
        Queue::fake();

        $alert = $this->alert();
        $claimer = app(AlertClaimer::class);

        $this->assertTrue($claimer->recordAndDispatch($alert->id, '2001.00000000'));
        $this->assertFalse($claimer->recordAndDispatch($alert->id, '2001.00000000'));

        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->count());
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function a_notification_is_dispatched_onto_the_notify_mail_queue(): void
    {
        Queue::fake();

        $alert = $this->alert();

        app(AlertClaimer::class)->recordAndDispatch($alert->id, '2001.00000000');

        Queue::assertPushedOn('notify.mail', SendNotificationJob::class);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function record_batch_returns_only_the_ids_that_won_the_cas(): void
    {
        Queue::fake();

        $active = $this->alert();
        $alreadyTriggered = $this->alert();
        app(AlertClaimer::class)->recordAndDispatch($alreadyTriggered->id, '1999.00000000');

        $claimed = app(AlertClaimer::class)->recordBatch([$active->id, $alreadyTriggered->id], '2001.00000000');

        $this->assertSame([$active->id], $claimed);
        $this->assertSame(AlertStatus::Triggered, $active->fresh()->status);
        $this->assertSame(1, AlertNotification::where('alert_id', $active->id)->count());
        $this->assertSame(1, AlertNotification::where('alert_id', $alreadyTriggered->id)->count());
    }

    /**
     * @throws Throwable
     */
    #[Test]
    public function a_pre_existing_notification_row_is_not_duplicated_or_re_dispatched(): void
    {
        Queue::fake();

        $alert = $this->alert();

        AlertNotification::query()->create([
            'alert_id' => $alert->id,
            'status' => NotificationStatus::Pending,
            'attempts' => 0,
        ]);

        $claimed = app(AlertClaimer::class)->recordBatch([$alert->id], '2001.00000000');

        $this->assertSame([$alert->id], $claimed);
        $this->assertSame(AlertStatus::Triggered, $alert->fresh()->status);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->count());
        Queue::assertNotPushed(SendNotificationJob::class);
    }
}
