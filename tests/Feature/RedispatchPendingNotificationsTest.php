<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\NotificationStatus;
use App\Enums\Symbol;
use App\Jobs\SendNotificationJob;
use App\Models\AlertNotification;
use App\Models\PriceAlert;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

class RedispatchPendingNotificationsTest extends FeatureTestCase
{
    private function triggeredAlert(): PriceAlert
    {
        return PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
            'status' => AlertStatus::Triggered,
            'triggered_price' => '2001.00000000',
            'triggered_at' => now(),
        ]);
    }

    private function pendingNotification(PriceAlert $alert, CarbonInterface $createdAt): AlertNotification
    {
        $notification = AlertNotification::query()->create([
            'alert_id' => $alert->id,
            'status' => NotificationStatus::Pending,
            'attempts' => 0,
        ]);

        DB::table('alert_notifications')->where('id', $notification->id)->update(['created_at' => $createdAt]);

        return $notification->refresh();
    }

    #[Test]
    public function re_dispatches_an_old_pending_row(): void
    {
        Queue::fake();
        Log::spy();

        $alert = $this->triggeredAlert();
        $notification = $this->pendingNotification($alert, now()->subSeconds(400));

        $this->artisan('notifications:redispatch-pending')->assertExitCode(0);

        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->notificationId === $notification->id);
        Queue::assertPushedOn('notify.mail', SendNotificationJob::class);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function leaves_a_fresh_pending_row_alone(): void
    {
        Queue::fake();

        $alert = $this->triggeredAlert();
        $this->pendingNotification($alert, now()->subSeconds(30));

        $this->artisan('notifications:redispatch-pending')->assertExitCode(0);

        Queue::assertNotPushed(SendNotificationJob::class);
    }

    #[Test]
    public function leaves_non_pending_rows_alone(): void
    {
        Queue::fake();

        $alert = $this->triggeredAlert();
        $sent = AlertNotification::query()->create([
            'alert_id' => $alert->id,
            'status' => NotificationStatus::Sent,
            'attempts' => 1,
        ]);
        DB::table('alert_notifications')->where('id', $sent->id)->update(['created_at' => now()->subSeconds(400)]);

        $this->artisan('notifications:redispatch-pending')->assertExitCode(0);

        Queue::assertNotPushed(SendNotificationJob::class);
    }
}
