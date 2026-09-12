<?php

namespace Tests\Feature;

use App\Contracts\PriceProvider;
use App\Enums\Symbol;
use App\Services\PriceIngestor;
use App\Support\PriceQuote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\TestCase;

abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->flushdb();
        Redis::connection('cache')->flushdb();
        $this->artisan('rabbitmq:declare-topology')->run();
    }

    protected function bindPriceProvider(PriceProvider $provider): void
    {
        $this->app->instance(PriceProvider::class, $provider);
        $this->app->forgetInstance(PriceIngestor::class);
    }

    protected function bindFixedPrice(string $price, ?CarbonImmutable $quotedAt = null): void
    {
        $this->bindPriceProvider(new class($price, $quotedAt ?? CarbonImmutable::now()) implements PriceProvider
        {
            public function __construct(
                private readonly string $price,
                private readonly CarbonImmutable $quotedAt,
            ) {}

            public function fetch(Symbol $symbol): PriceQuote
            {
                return new PriceQuote($symbol, $this->price, $this->quotedAt);
            }
        });
    }

    protected function fakeGoldApi(string $price, ?CarbonImmutable $quotedAt = null): void
    {
        $at = $quotedAt ?? CarbonImmutable::now();

        Http::fake(['*goldapi.io/*' => Http::response([
            'timestamp' => $at->getTimestamp(),
            'datetime' => $at->toIso8601String(),
            'metal' => 'XAU', 'currency' => 'USD',
            'exchange' => 'FOREXCOM', 'symbol' => 'FOREXCOM:XAUUSD',
            'prev_close_price' => $price, 'open_price' => $price,
            'low_price' => $price, 'high_price' => $price,
            'open_time' => $at->getTimestamp(),
            'price' => $price, 'unit' => 'troy_ounce',
            'change' => 0, 'change_percent' => 0,
            'ask' => $price, 'bid' => $price,
            'price_per_unit' => ['troy_ounce' => $price, 'gram' => $price, 'kilogram' => $price],
            'melt_price_per_gram' => ['24k' => $price, '22k' => $price, '18k' => $price],
            'currency_info' => ['code' => 'USD', 'name' => 'United States Dollar', 'symbol' => 'US$'],
        ], 200)]);
    }

    protected function rabbitChannel()
    {
        $host = config('queue.connections.rabbitmq.hosts.0');

        $connection = new AMQPStreamConnection(
            $host['host'], $host['port'], $host['user'], $host['password'], $host['vhost'],
        );

        $this->beforeApplicationDestroyed(fn () => $connection->close());

        return $connection->channel();
    }

    protected function subscribePriority($channel, string $queue): callable
    {
        $received = null;

        $channel->basic_consume(
            $queue, '', false, false, false, false,
            function (AMQPMessage $message) use (&$received) {
                $received = $message;
            },
            null,
            new AMQPTable(['x-priority' => 10]),
        );

        return function () use ($channel, &$received): ?AMQPMessage {
            try {
                while ($received === null) {
                    $channel->wait(null, false, 5);
                }
            } catch (AMQPTimeoutException) {
            }

            return $received;
        };
    }
}
