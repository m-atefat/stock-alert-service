<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors\RabbitMQConnector;

class DeclareRabbitTopologyCommand extends Command
{
    protected $signature = 'rabbitmq:declare-topology';

    protected $description = 'Declare the prices/notifications exchange/queue/DLX topology.';

    /**
     * @throws Exception
     */
    public function handle(RabbitMQConnector $connector): int
    {
        $queue = $connector->connect(config('queue.connections.rabbitmq'));

        $queue->declareExchange('prices', 'topic', true, false);
        $queue->declareExchange('prices.ticks.dlx', 'topic', true, false);

        $queue->declareQueue('prices.ticks', true, false, [
            'x-dead-letter-exchange' => 'prices.ticks.dlx',
            'x-dead-letter-routing-key' => 'prices.ticks.dlq',
        ]);
        $queue->bindQueue('prices.ticks', 'prices', 'price.*');

        $queue->declareQueue('prices.ticks.dlq', true, false);
        $queue->bindQueue('prices.ticks.dlq', 'prices.ticks.dlx', 'prices.ticks.dlq');
        $queue->bindQueue('prices.ticks.dlq', 'prices.ticks.dlx', 'price.*.dlq');
        $queue->bindQueue('prices.ticks.dlq', 'prices.ticks.dlx', 'prices.drain.dlq');

        $queue->declareQueue('prices.drain', true, false, [
            'x-dead-letter-exchange' => 'prices.ticks.dlx',
            'x-dead-letter-routing-key' => 'prices.drain.dlq',
        ]);
        $queue->bindQueue('prices.drain', 'prices', 'prices.drain');

        $queue->declareExchange('notifications', 'topic', true, false);
        $queue->declareExchange('notify.mail.dlx', 'topic', true, false);

        $queue->declareQueue('notify.mail', true, false, [
            'x-dead-letter-exchange' => 'notify.mail.dlx',
            'x-dead-letter-routing-key' => 'notify.mail.dlq',
        ]);
        $queue->bindQueue('notify.mail', 'notifications', 'notify.mail');

        $queue->declareQueue('notify.mail.dlq', true, false);
        $queue->bindQueue('notify.mail.dlq', 'notify.mail.dlx', 'notify.mail.dlq');

        $this->info('Topology declared: prices -[price.*]-> prices.ticks -(dlx)-> prices.ticks.dlq; '
            .'prices -[prices.drain]-> prices.drain -(dlx)-> prices.ticks.dlq; '
            .'notifications -[notify.mail]-> notify.mail -(dlx)-> notify.mail.dlq');

        return self::SUCCESS;
    }
}
