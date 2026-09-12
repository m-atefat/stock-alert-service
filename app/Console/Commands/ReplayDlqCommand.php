<?php

namespace App\Console\Commands;

use App\Support\RabbitPublisher;
use Exception;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

class ReplayDlqCommand extends Command
{
    protected $signature = 'rabbitmq:replay-dlq
        {queue : the live queue to replay onto, e.g. prices.ticks or notify.mail}
        {--limit=1000}
        {--keep-attempts : preserve the original attempts count instead of replaying as a fresh delivery}';

    protected $description = "Replay messages from a queue's dead-letter queue back onto the live queue.";

    /**
     * @throws Exception
     */
    public function handle(RabbitPublisher $publisher): int
    {
        $queue = (string) $this->argument('queue');
        $dlq = "{$queue}.dlq";

        $host = config('queue.connections.rabbitmq.hosts.0');

        $connection = new AMQPStreamConnection(
            $host['host'], $host['port'], $host['user'], $host['password'], $host['vhost'],
        );
        $channel = $connection->channel();

        $replayed = 0;
        $limit = (int) $this->option('limit');
        $keepAttempts = (bool) $this->option('keep-attempts');

        for ($i = 0; $i < $limit; $i++) {
            $message = $channel->basic_get($dlq);

            if ($message === null) {
                break;
            }

            $headers = $keepAttempts ? $message->get_properties()['application_headers'] ?? null : null;

            $published = false;

            try {
                $published = $publisher->publish('', $queue, $message->getBody(), headers: $headers);
            } catch (Throwable $e) {
                $this->error("republish failed: {$e->getMessage()}");
            }

            if (! $published) {
                $channel->basic_nack($message->getDeliveryTag(), false, true);
                $this->error('stopping replay after a failed republish');
                break;
            }

            $channel->basic_ack($message->getDeliveryTag());
            $replayed++;
        }

        $publisher->close();
        $channel->close();
        $connection->close();

        $this->info("replayed {$replayed} message(s) from {$dlq} onto {$queue}");

        return self::SUCCESS;
    }
}
