<?php

namespace App\Redis;

use App\Enums\AlertDirection;
use App\Enums\Symbol;
use App\Support\Price;
use Illuminate\Redis\Connections\Connection;
use RuntimeException;
use Throwable;

class AlertIndex
{
    private ?string $matchScript = null;

    private ?string $scriptSha = null;

    public function __construct(
        private readonly Connection $redis,
    ) {}

    public static function scaledScore(string $decimalPrice): string
    {
        return (new Price($decimalPrice))->scaledScore();
    }

    public function aboveKey(Symbol $symbol): string
    {
        return "alerts:{{$symbol->value}}:above";
    }

    public function belowKey(Symbol $symbol): string
    {
        return "alerts:{{$symbol->value}}:below";
    }

    public function keyFor(Symbol $symbol, AlertDirection $direction): string
    {
        return $direction === AlertDirection::Above
            ? $this->aboveKey($symbol)
            : $this->belowKey($symbol);
    }

    public function add(Symbol $symbol, AlertDirection $direction, int $alertId, string $targetPrice): void
    {
        $this->redis->zadd($this->keyFor($symbol, $direction), self::scaledScore($targetPrice), $alertId);
    }

    /**
     * @param  array<int, string>  $idToTargetPrice  alert id => decimal target price
     */
    public function addMany(Symbol $symbol, AlertDirection $direction, array $idToTargetPrice, string $keySuffix = ''): void
    {
        $key = $this->keyFor($symbol, $direction).$keySuffix;

        $this->redis->pipeline(function ($pipe) use ($key, $idToTargetPrice) {
            foreach ($idToTargetPrice as $id => $price) {
                $pipe->zadd($key, self::scaledScore($price), $id);
            }
        });
    }

    public function remove(Symbol $symbol, AlertDirection $direction, int $alertId): void
    {
        $this->redis->zrem($this->keyFor($symbol, $direction), $alertId);
    }

    /**
     * @param  array<int, AlertDirection>  $idToDirection  alert id => direction
     */
    public function removeMany(Symbol $symbol, array $idToDirection): void
    {
        $this->redis->pipeline(function ($pipe) use ($symbol, $idToDirection) {
            foreach ($idToDirection as $id => $direction) {
                $pipe->zrem($this->keyFor($symbol, $direction), $id);
            }
        });
    }

    public function isIndexed(Symbol $symbol, AlertDirection $direction, int $alertId): bool
    {
        $score = $this->redis->zscore($this->keyFor($symbol, $direction), $alertId);

        return $score !== false;
    }

    public function count(Symbol $symbol, AlertDirection $direction): int
    {
        return (int) $this->redis->zcard($this->keyFor($symbol, $direction));
    }

    public function isEmpty(Symbol $symbol): bool
    {
        return $this->count($symbol, AlertDirection::Above) === 0
            && $this->count($symbol, AlertDirection::Below) === 0;
    }

    /**
     * @return array<int>
     *
     * @throws Throwable
     */
    public function matchAbove(Symbol $symbol, string $price, int $limit): array
    {
        return $this->match($this->aboveKey($symbol), '-inf', self::scaledScore($price), $limit);
    }

    /**
     * @return array<int>
     *
     * @throws Throwable
     */
    public function matchBelow(Symbol $symbol, string $price, int $limit): array
    {
        return $this->match($this->belowKey($symbol), self::scaledScore($price), '+inf', $limit);
    }

    /**
     * @return array<int>
     *
     * @throws Throwable
     */
    private function match(string $zsetKey, string $min, string $max, int $limit): array
    {
        return $this->evalScript([$zsetKey], [$min, $max, $limit]);
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, string|int>  $argv
     * @return array<int>
     *
     * @throws Throwable
     */
    private function evalScript(array $keys, array $argv): array
    {
        $this->scriptSha ??= $this->redis->script('load', $this->script());

        $result = $this->redis->command('evalsha', [$this->scriptSha, [...$keys, ...$argv], count($keys)]);

        if ($result === false) {
            $lastError = (string) $this->redis->client()->getLastError();

            if (str_contains($lastError, 'NOSCRIPT')) {
                $this->scriptSha = $this->redis->script('load', $this->script());
                $result = $this->redis->command('evalsha', [$this->scriptSha, [...$keys, ...$argv], count($keys)]);
            } else {
                throw new RuntimeException("match_alerts script failed: {$lastError}");
            }
        }

        if ($result === false) {
            $lastError = (string) $this->redis->client()->getLastError();

            throw new RuntimeException("match_alerts script failed after NOSCRIPT reload: {$lastError}");
        }

        return array_map('intval', $result);
    }

    private function script(): string
    {
        return $this->matchScript ??= file_get_contents(app_path('Redis/Scripts/match_alerts.lua'));
    }
}
