<?php

namespace App\Console\Commands;

use App\Enums\AlertStatus;
use App\Enums\NotificationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAlertsCommand extends Command
{
    protected $signature = 'alerts:prune
        {--days= : retain triggered alerts for this many days (defaults to config(prices.retention_days))}
        {--chunk=1000 : rows deleted per statement}';

    protected $description = 'Hard-delete triggered alerts past the retention window, once their notification has reached a terminal state.';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('prices.retention_days');
        $cutoff = now()->subDays($days);
        $chunk = (int) $this->option('chunk');
        $deleted = 0;

        while (true) {
            $ids = DB::table('price_alerts')
                ->where('status', AlertStatus::Triggered->value)
                ->where('triggered_at', '<', $cutoff)
                ->whereExists(fn ($q) => $q
                    ->selectRaw('1')
                    ->from('alert_notifications')
                    ->whereColumn('alert_notifications.alert_id', 'price_alerts.id')
                    ->whereIn('alert_notifications.status', [
                        NotificationStatus::Sent->value,
                        NotificationStatus::Failed->value,
                    ]))
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('price_alerts')->whereIn('id', $ids)->delete();
        }

        $this->info("pruned {$deleted} alert(s) triggered before {$cutoff->toDateTimeString()}");

        return self::SUCCESS;
    }
}
