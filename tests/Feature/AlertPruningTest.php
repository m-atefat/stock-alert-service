<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\NotificationStatus;
use App\Enums\Symbol;
use App\Models\AlertNotification;
use App\Models\PriceAlert;
use Carbon\CarbonInterface;
use PHPUnit\Framework\Attributes\Test;

class AlertPruningTest extends FeatureTestCase
{
    private function triggeredAlert(CarbonInterface $triggeredAt): PriceAlert
    {
        return PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
            'status' => AlertStatus::Triggered,
            'triggered_price' => '2001.00000000',
            'triggered_at' => $triggeredAt,
        ]);
    }

    private function notificationFor(PriceAlert $alert, NotificationStatus $status): AlertNotification
    {
        return AlertNotification::query()->create([
            'alert_id' => $alert->id,
            'status' => $status,
            'attempts' => 1,
        ]);
    }

    #[Test]
    public function prunes_a_triggered_alert_past_the_window_whose_notification_is_sent(): void
    {
        $alert = $this->triggeredAlert(now()->subDays(31));
        $notification = $this->notificationFor($alert, NotificationStatus::Sent);

        $this->artisan('alerts:prune', ['--days' => 30])->assertExitCode(0);

        $this->assertModelMissing($alert);
        $this->assertModelMissing($notification);
    }

    #[Test]
    public function prunes_a_triggered_alert_past_the_window_whose_notification_is_failed(): void
    {
        $alert = $this->triggeredAlert(now()->subDays(31));
        $notification = $this->notificationFor($alert, NotificationStatus::Failed);

        $this->artisan('alerts:prune', ['--days' => 30])->assertExitCode(0);

        $this->assertModelMissing($alert);
        $this->assertModelMissing($notification);
    }

    #[Test]
    public function does_not_prune_inside_the_retention_window(): void
    {
        $alert = $this->triggeredAlert(now()->subDays(10));
        $this->notificationFor($alert, NotificationStatus::Sent);

        $this->artisan('alerts:prune', ['--days' => 30])->assertExitCode(0);

        $this->assertModelExists($alert);
    }

    #[Test]
    public function does_not_prune_when_the_notification_is_sending(): void
    {
        $alert = $this->triggeredAlert(now()->subDays(31));
        $this->notificationFor($alert, NotificationStatus::Sending);

        $this->artisan('alerts:prune', ['--days' => 30])->assertExitCode(0);

        $this->assertModelExists($alert);
    }

    #[Test]
    public function does_not_prune_when_the_notification_is_pending(): void
    {
        $alert = $this->triggeredAlert(now()->subDays(31));
        $this->notificationFor($alert, NotificationStatus::Pending);

        $this->artisan('alerts:prune', ['--days' => 30])->assertExitCode(0);

        $this->assertModelExists($alert);
    }

    #[Test]
    public function does_not_prune_an_active_alert_whatever_its_age(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
            'status' => AlertStatus::Active,
            'created_at' => now()->subDays(365),
        ]);

        $this->artisan('alerts:prune', ['--days' => 0])->assertExitCode(0);

        $this->assertModelExists($alert);
    }

    #[Test]
    public function prunes_past_a_single_chunk(): void
    {
        $chunk = 3;

        $alerts = collect(range(1, $chunk + 1))->map(function () {
            $alert = $this->triggeredAlert(now()->subDays(31));
            $this->notificationFor($alert, NotificationStatus::Sent);

            return $alert;
        });

        $this->artisan('alerts:prune', ['--days' => 30, '--chunk' => $chunk])->assertExitCode(0);

        $alerts->each(fn (PriceAlert $alert) => $this->assertModelMissing($alert));
    }
}
