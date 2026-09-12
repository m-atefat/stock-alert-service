<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfigFactory;

class QueueTopologyConfigTest extends TestCase
{
    #[Test]
    public function the_rabbitmq_connection_exposes_the_prices_exchange_through_the_factory(): void
    {
        $config = QueueConfigFactory::make(config('queue.connections.rabbitmq'));

        $this->assertSame('prices', $config->getExchange());
        $this->assertSame('topic', $config->getExchangeType());
        $this->assertSame('%s', $config->getExchangeRoutingKey());
        $this->assertTrue($config->isRerouteFailed());
        $this->assertSame('prices.ticks.dlx', $config->getFailedExchange());
        $this->assertSame('%s.dlq', $config->getFailedRoutingKey());
    }

    #[Test]
    public function the_rabbitmq_notifications_connection_exposes_the_notifications_exchange_through_the_factory(): void
    {
        $config = QueueConfigFactory::make(config('queue.connections.rabbitmq_notifications'));

        $this->assertSame('notifications', $config->getExchange());
        $this->assertSame('topic', $config->getExchangeType());
        $this->assertSame('%s', $config->getExchangeRoutingKey());
        $this->assertTrue($config->isRerouteFailed());
        $this->assertSame('notify.mail.dlx', $config->getFailedExchange());
        $this->assertSame('%s.dlq', $config->getFailedRoutingKey());
    }
}
