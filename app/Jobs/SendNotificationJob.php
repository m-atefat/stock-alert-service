<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\AlertNotification;
use App\Notifications\PriceAlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $notificationId,
    ) {
        $this->onConnection('rabbitmq_notifications');
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $acquired = DB::table('alert_notifications')
            ->where('id', $this->notificationId)
            ->whereIn('status', [
                NotificationStatus::Pending->value,
                NotificationStatus::Sending->value,
            ])
            ->where('attempts', '<', $this->tries)
            ->update([
                'status' => NotificationStatus::Sending->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($acquired === 0) {
            DB::table('alert_notifications')
                ->where('id', $this->notificationId)
                ->where('status', NotificationStatus::Sending->value)
                ->where('attempts', '>=', $this->tries)
                ->update([
                    'status' => NotificationStatus::Failed->value,
                    'error' => 'worker died mid-send: attempt budget exhausted while Sending',
                    'updated_at' => now(),
                ]);

            return;
        }

        $notification = AlertNotification::query()->with('alert.user')->find($this->notificationId);

        if ($notification === null) {
            return;
        }

        $alert = $notification->alert;

        try {
            $alert->user->notify(new PriceAlertNotification($alert));
        } catch (Throwable $e) {
            $this->updateIfNotTerminal([
                'status' => NotificationStatus::Pending->value,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->updateIfNotTerminal([
            'status' => NotificationStatus::Sent->value,
            'sent_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        $this->updateIfNotTerminal([
            'status' => NotificationStatus::Failed->value,
            'error' => $e?->getMessage(),
        ]);
    }

    private function updateIfNotTerminal(array $values): int
    {
        return DB::table('alert_notifications')
            ->where('id', $this->notificationId)
            ->whereIn('status', [
                NotificationStatus::Pending->value,
                NotificationStatus::Sending->value,
            ])
            ->update($values + ['updated_at' => now()]);
    }
}
