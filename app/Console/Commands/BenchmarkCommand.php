<?php

namespace App\Console\Commands;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\NotificationStatus;
use App\Enums\Symbol;
use App\Jobs\PriceTicked;
use App\Models\PriceAlert;
use App\Models\User;
use App\Redis\AlertIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class BenchmarkCommand extends Command
{
    protected $signature = 'alerts:benchmark
        {--scales=1000,100000,1000000 : index sizes to measure}
        {--skip-seed : reuse whatever is already seeded instead of re-seeding}
        {--iterations=30 : number of trials to time per scale}
        {--e2e=20 : number of alerts to run through the full pipeline for the end-to-end benchmark}
        {--force : allow running against APP_ENV=production, and skip the dirty-index guard}';

    protected $description = 'Seed alerts at increasing scale and measure claim latency as the index grows.';

    private const string BASE_PRICE = '1000.00000000';

    private const string STEP = '0.01000000';

    private const int CLAIM_BATCH = 100;

    /**
     * @throws Throwable
     */
    public function handle(AlertIndex $index): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('refusing to seed/delete benchmark data against a production environment — pass --force to override.');

            return self::FAILURE;
        }

        $iterations = (int) $this->option('iterations');
        $e2eCount = (int) $this->option('e2e');

        if ($iterations < 1) {
            $this->error('--iterations must be at least 1.');

            return self::FAILURE;
        }

        if ($e2eCount < 0) {
            $this->error('--e2e must be 0 or greater.');

            return self::FAILURE;
        }

        DB::connection()->disableQueryLog();

        ini_set('memory_limit', '512M');

        $symbol = Symbol::XauUsd;

        if (! $this->option('skip-seed')) {
            $existing = $index->count($symbol, AlertDirection::Above) + $index->count($symbol, AlertDirection::Below);

            if ($existing > 0 && ! $this->option('force')) {
                $this->error(sprintf(
                    'index already holds %s member(s) for %s — the scaling table would measure against those, '
                    .'not the seeded scale. Clear it or pass --force.',
                    number_format($existing), $symbol->value,
                ));

                return self::FAILURE;
            }
        }

        $user = User::query()->firstOrCreate(
            ['email' => 'benchmark@price-alerts.test'],
            ['name' => 'Benchmark User', 'password' => bcrypt(Str::random(32))],
        );

        $this->benchmarkEndToEnd($symbol, $index, $e2eCount);

        $scales = array_map('intval', explode(',', (string) $this->option('scales')));
        $rows = [];

        foreach ($scales as $n) {
            if (! $this->option('skip-seed')) {
                $this->seed($n, $symbol, $user->id, $index);
            }

            $rows[] = $this->benchmarkAtScale($symbol, $index, $n, $iterations);
        }

        $this->newLine();
        $this->table(['seeded', 'in index', 'tick matching nothing', 'tick claiming '.self::CLAIM_BATCH], $rows);

        $this->cleanup($user, $index);

        return self::SUCCESS;
    }

    private function seed(int $n, Symbol $symbol, int $userId, AlertIndex $index): void
    {
        $this->info("seeding {$n} alerts for {$symbol->value}...");

        $stale = PriceAlert::query()
            ->where('symbol', $symbol->value)
            ->where('user_id', $userId)
            ->get(['id', 'direction']);

        if ($stale->isNotEmpty()) {
            Redis::connection()->pipeline(function ($pipe) use ($stale, $index, $symbol) {
                foreach ($stale as $alert) {
                    $pipe->zrem($index->keyFor($symbol, $alert->direction), $alert->id);
                }
            });
        }

        DB::table('price_alerts')->where('symbol', $symbol->value)->where('user_id', $userId)->delete();

        $bar = $this->output->createProgressBar($n);
        $chunkSize = 5000;
        $rows = [];

        for ($i = 0; $i < $n; $i++) {
            $direction = $i % 2 === 0 ? AlertDirection::Above : AlertDirection::Below;
            $price = bcadd(self::BASE_PRICE, bcmul(self::STEP, (string) intdiv($i, 2), 8), 8);

            $rows[] = [
                'user_id' => $userId,
                'symbol' => $symbol->value,
                'target_price' => $price,
                'direction' => $direction->value,
                'status' => AlertStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($rows) >= $chunkSize) {
                DB::table('price_alerts')->insert($rows);
                $bar->advance(count($rows));
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('price_alerts')->insert($rows);
            $bar->advance(count($rows));
        }

        $bar->finish();
        $this->newLine();

        $this->info('populating the Redis index from the seeded rows...');

        PriceAlert::query()->active()->where('symbol', $symbol->value)->where('user_id', $userId)
            ->chunkById($chunkSize, function ($alerts) use ($index, $symbol) {
                foreach ($alerts->groupBy(fn ($alert) => $alert->direction->value) as $directionValue => $group) {
                    $index->addMany($symbol, AlertDirection::from($directionValue), $group->pluck('target_price', 'id')->all());
                }
            });
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     *
     * @throws Throwable
     */
    private function benchmarkAtScale(Symbol $symbol, AlertIndex $index, int $n, int $iterations): array
    {
        $batch = self::CLAIM_BATCH;

        $measured = $index->count($symbol, AlertDirection::Above) + $index->count($symbol, AlertDirection::Below);

        $available = intdiv($n, 2);
        $needed = $iterations * $batch;

        if ($available < $needed) {
            $this->warn(sprintf(
                'scale %s: %d Above-side alerts available but %d claims requested — the set exhausts, '
                .'so the claim column is reported as n/a rather than silently timing empty claims.',
                number_format($n), $available, $needed,
            ));
        }

        $noMatchThreshold = bcsub(self::BASE_PRICE, self::STEP, 8);
        $noMatchSamples = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $index->matchAbove($symbol, $noMatchThreshold, $batch);
            $noMatchSamples[] = (hrtime(true) - $start) / 1e6;
        }

        $threshold = self::BASE_PRICE;
        $claimSamples = [];

        for ($i = 0; $i < $iterations; $i++) {
            $threshold = bcadd($threshold, bcmul(self::STEP, (string) $batch, 8), 8);

            $start = hrtime(true);
            $claimed = $index->matchAbove($symbol, $threshold, $batch);
            $elapsed = (hrtime(true) - $start) / 1e6;

            if (count($claimed) === $batch) {
                $claimSamples[] = $elapsed;
            }
        }

        $claimP50 = $this->percentile($claimSamples, 0.50);

        return [
            number_format($n),
            number_format($measured),
            sprintf('%.2f ms', $this->percentile($noMatchSamples, 0.50)),
            $claimP50 === null ? 'n/a' : sprintf('%.2f ms', $claimP50),
        ];
    }

    private function benchmarkEndToEnd(Symbol $symbol, AlertIndex $index, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $this->resetMailpit();

        $this->info("running {$count} alert(s) through the full pipeline (needs match-consumer and mail-consumer running)...");

        $alertIds = [];
        $target = bcadd(self::BASE_PRICE, self::STEP, 8);

        for ($i = 0; $i < $count; $i++) {
            $email = 'bench-e2e-'.Str::uuid().'@price-alerts.test';
            $user = User::query()->create(['name' => 'E2E Bench', 'email' => $email, 'password' => bcrypt(Str::random(32))]);

            $alert = PriceAlert::query()->create([
                'user_id' => $user->id,
                'symbol' => $symbol,
                'target_price' => $target,
                'direction' => AlertDirection::Above,
            ]);

            $index->add($symbol, AlertDirection::Above, $alert->id, $target);
            $alertIds[] = $alert->id;
        }

        $dispatchedAt = hrtime(true);

        PriceTicked::dispatch($symbol, bcadd($target, self::STEP, 8), 'bench-e2e:'.Str::uuid())
            ->onQueue("price.{$symbol->value}");

        $pending = array_flip($alertIds);
        $samples = [];
        $deadline = now()->addSeconds(30);

        while ($pending !== [] && now()->lessThan($deadline)) {
            $sent = DB::table('alert_notifications')
                ->whereIn('alert_id', array_keys($pending))
                ->where('status', NotificationStatus::Sent->value)
                ->pluck('alert_id');

            foreach ($sent as $id) {
                $samples[] = (hrtime(true) - $dispatchedAt) / 1e6;
                unset($pending[$id]);
            }

            usleep(20_000);
        }

        if ($samples === []) {
            $this->warn(sprintf(
                'dispatched %d but none reached Sent — check match-consumer and mail-consumer, '
                .'and that price.%s routed into prices.ticks (see PriceTickRoutingTest)',
                count($alertIds), $symbol->value,
            ));

            return;
        }

        $this->reportPercentiles('End-to-end (dispatch -> notifications.sent_at)', $samples);
    }

    private function resetMailpit(): void
    {
        try {
            Http::delete('http://mailpit:8025/api/v1/messages');
        } catch (Throwable $e) {
            $this->warn("failed to reset mailpit inbox: {$e->getMessage()}");
        }
    }

    private function cleanup(User $benchmarkUser, AlertIndex $index): void
    {
        $this->info('cleaning up benchmark data...');

        $e2eUserIds = User::query()->where('email', 'like', 'bench-e2e-%@price-alerts.test')->pluck('id');
        $userIds = $e2eUserIds->push($benchmarkUser->id);

        PriceAlert::query()->whereIn('user_id', $userIds)
            ->chunkById(5000, function ($alerts) use ($index) {
                foreach ($alerts->groupBy(fn ($alert) => $alert->symbol->value) as $symbolValue => $group) {
                    $index->removeMany(Symbol::from($symbolValue), $group->pluck('direction', 'id')->all());
                }
            });

        User::query()->whereIn('id', $e2eUserIds)->delete();
        PriceAlert::query()->where('user_id', $benchmarkUser->id)->delete();
    }

    /**
     * @param  array<float>  $samplesMs
     */
    private function reportPercentiles(string $label, array $samplesMs): void
    {
        $n = count($samplesMs);

        $this->newLine();
        $this->line("<fg=cyan>{$label}</> ({$n} samples)");

        if ($n === 0) {
            $this->line('  no samples captured');

            return;
        }

        $this->line(sprintf(
            '  p50=%.2fms  p95=%.2fms  p99=%.2fms  max=%.2fms',
            $this->percentile($samplesMs, 0.50),
            $this->percentile($samplesMs, 0.95),
            $this->percentile($samplesMs, 0.99),
            max($samplesMs),
        ));
    }

    /**
     * @param  array<float>  $samplesMs
     */
    private function percentile(array $samplesMs, float $p): ?float
    {
        if ($samplesMs === []) {
            return null;
        }

        sort($samplesMs);
        $n = count($samplesMs);

        return $samplesMs[min($n - 1, (int) ceil($p * $n) - 1)];
    }
}
