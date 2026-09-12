<?php

namespace App\Support;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class RabbitPublisher
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $vhost,
    ) {}

    public function publish(string $exchange, string $routingKey, string $body, int $confirmTimeoutSeconds = 5, ?AMQPTable $headers = null): bool
    {
        $channel = $this->channel();

        $confirmed = false;
        $nacked = false;
        $channel->set_ack_handler(function () use (&$confirmed): void {
            $confirmed = true;
        });

        $channel->set_nack_handler(function () use (&$nacked): void {
            $nacked = true;
        });

        $message = new AMQPMessage($body, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        if ($headers !== null) {
            $message->set('application_headers', $headers);
        }

        $channel->basic_publish($message, $exchange, $routingKey);

        try {
            $channel->wait_for_pending_acks($confirmTimeoutSeconds);
        } catch (Throwable) {
            $this->close();

            return false;
        }

        return $confirmed && ! $nacked;
    }

    /**
     * @throws Exception
     */
    private function channel(): AMQPChannel
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        $this->connection = new AMQPStreamConnection(
            $this->host,
            $this->port,
            $this->user,
            $this->password,
            $this->vhost,
            connection_timeout: 3,
            read_write_timeout: 3,
        );

        $this->channel = $this->connection->channel();
        $this->channel->confirm_select();

        return $this->channel;
    }

    public function close(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (Throwable) {
        }

        $this->channel = null;
        $this->connection = null;
    }
}
