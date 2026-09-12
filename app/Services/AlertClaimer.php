<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\NotificationStatus;
use App\Enums\Symbol;
use App\Jobs\SendNotificationJob;
use App\Redis\AlertIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

readonly class AlertClaimer
{
    public function __construct(
        private AlertIndex $index,
    ) {}

    /**
     * @return array{claimed: int, more: bool}
     *
     * @throws Throwable
     */
    public function claimOnePass(Symbol $symbol, string $price): array
    {
        $batchSize = (int) config('prices.match_batch_size');

        $above = $this->index->matchAbove($symbol, $price, $batchSize);
        $below = $this->index->matchBelow($symbol, $price, $batchSize);

        $this->recordBatch([...$above, ...$below], $price);

        return [
            'claimed' => count($above) + count($below),
            'more' => count($above) === $batchSize || count($below) === $batchSize,
        ];
    }

    /**
     * @throws Throwable
     */
    public function recordAndDispatch(int $alertId, string $triggeredPrice): bool
    {
        return in_array($alertId, $this->recordBatch([$alertId], $triggeredPrice), true);
    }

    /**
     * @param  array<int>  $alertIds
     * @return array<int> the subset of $alertIds that actually won the claim
     *
     * @throws Throwable
     */
    public function recordBatch(array $alertIds, string $triggeredPrice): array
    {
        $alertIds = array_values(array_unique($alertIds));

        if ($alertIds === []) {
            return [];
        }

        $notificationIds = [];

        $claimedIds = DB::transaction(function () use ($alertIds, $triggeredPrice, &$notificationIds) {
            $now = now();

            $placeholders = implode(',', array_fill(0, count($alertIds), '?'));

            $claimed = DB::select(
                "UPDATE price_alerts
                    SET status = ?, triggered_price = ?, triggered_at = ?, updated_at = ?
                  WHERE id IN ($placeholders) AND status = ?
                  RETURNING id",
                [
                    AlertStatus::Triggered->value, $triggeredPrice, $now, $now,
                    ...$alertIds, AlertStatus::Active->value,
                ],
            );

            $claimedIds = array_map(static fn ($row) => (int) $row->id, $claimed);

            if ($claimedIds === []) {
                return $claimedIds;
            }

            $values = [];
            $bindings = [];

            foreach ($claimedIds as $alertId) {
                $values[] = '(?,?,?,?,?)';
                array_push($bindings, $alertId, NotificationStatus::Pending->value, 0, $now, $now);
            }

            $inserted = DB::select(
                'INSERT INTO alert_notifications (alert_id, status, attempts, created_at, updated_at)
                 VALUES '.implode(',', $values).'
                 ON CONFLICT (alert_id) DO NOTHING
                 RETURNING id',
                $bindings,
            );

            $notificationIds = array_map(static fn ($row) => (int) $row->id, $inserted);

            return $claimedIds;
        });

        if ($notificationIds !== []) {
            Queue::connection('rabbitmq_notifications')->bulk(
                array_map(static fn (int $id) => new SendNotificationJob($id), $notificationIds),
                '',
                'notify.mail',
            );
        }

        return $claimedIds;
    }
}
