<?php

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\AlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class RedispatchPendingNotificationsCommand extends Command
{
    protected $signature = 'notifications:redispatch-pending {--older-than= : seconds a Pending row must have existed before this will touch it}';

    protected $description = 'Re-dispatch Pending notifications old enough to indicate their original publish was lost.';

    public function handle(): int
    {
        $olderThan = $this->option('older-than') !== null
            ? (int) $this->option('older-than')
            : (int) config('prices.notifications.redispatch_after');

        $cutoff = now()->subSeconds($olderThan);
        $redispatched = 0;

        AlertNotification::query()
            ->where('status', NotificationStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->select(['id'])
            ->chunkById(1000, function ($rows) use (&$redispatched) {
                Log::warning('re-dispatching stranded pending notifications — the original publish was likely lost', [
                    'notification_ids' => $rows->pluck('id')->all(),
                ]);

                Queue::connection('rabbitmq_notifications')->bulk(
                    $rows->map(fn (AlertNotification $row) => new SendNotificationJob($row->id))->all(),
                    '',
                    'notify.mail',
                );

                $redispatched += $rows->count();
            });

        $this->info("re-dispatched {$redispatched} stranded pending notification(s)");

        return self::SUCCESS;
    }
}
