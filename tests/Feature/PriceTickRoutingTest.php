<?php

namespace Tests\Feature;

use App\Enums\Symbol;
use App\Jobs\PriceTicked;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PHPUnit\Framework\Attributes\Test;

class PriceTickRoutingTest extends FeatureTestCase
{
    #[Test]
    public function dispatching_price_ticked_never_auto_declares_a_queue_named_after_the_routing_key(): void
    {
        $channel = $this->rabbitChannel();

        PriceTicked::dispatch(Symbol::XauUsd, '2001.00000000', 'routing-test')->onQueue('price.'.Symbol::XauUsd->value);

        try {
            $channel->queue_declare('price.XAUUSD', true);
            $this->fail('expected queue [price.XAUUSD] not to exist — the routing key leaked into an auto-declared queue');
        } catch (AMQPProtocolChannelException $e) {
            $this->assertSame(404, $e->amqp_reply_code, 'expected a not-found error, got something else');
        }
    }

    #[Test]
    public function dispatching_price_ticked_delivers_into_the_physical_prices_ticks_queue(): void
    {
        $channel = $this->rabbitChannel();
        $channel->queue_purge('prices.ticks');

        $await = $this->subscribePriority($channel, 'prices.ticks');

        PriceTicked::dispatch(Symbol::XauUsd, '2001.00000000', 'routing-test')->onQueue('price.'.Symbol::XauUsd->value);

        $message = $await();
        $this->assertNotNull($message, 'expected the dispatched job to arrive on prices.ticks');

        $channel->basic_ack($message->getDeliveryTag());
    }
}
