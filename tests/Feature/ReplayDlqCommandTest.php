<?php

namespace Tests\Feature;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\Attributes\Test;

class ReplayDlqCommandTest extends FeatureTestCase
{
    private function seedDlqMessage($channel, int $attempts): void
    {
        $body = json_encode(['test' => 'dlq-payload']);

        $message = new AMQPMessage($body, ['content_type' => 'application/json', 'delivery_mode' => 2]);
        $message->set('application_headers', new AMQPTable(['laravel' => ['attempts' => $attempts]]));

        $channel->basic_publish($message, '', 'notify.mail.dlq');
    }

    #[Test]
    public function replays_a_message_as_a_fresh_delivery_by_default(): void
    {
        $channel = $this->rabbitChannel();
        $channel->queue_purge('notify.mail');
        $channel->queue_purge('notify.mail.dlq');

        $this->seedDlqMessage($channel, attempts: 5);

        $await = $this->subscribePriority($channel, 'notify.mail');

        $this->artisan('rabbitmq:replay-dlq', ['queue' => 'notify.mail', '--limit' => 1])->assertExitCode(0);

        $message = $await();
        $this->assertNotNull($message);

        try {
            $message->get('application_headers');
            $this->fail('expected no application_headers property on a reset replay');
        } catch (\OutOfBoundsException) {
            $this->assertTrue(true);
        }

        $channel->basic_ack($message->getDeliveryTag());
    }

    #[Test]
    public function keep_attempts_preserves_the_original_count(): void
    {
        $channel = $this->rabbitChannel();
        $channel->queue_purge('notify.mail');
        $channel->queue_purge('notify.mail.dlq');

        $this->seedDlqMessage($channel, attempts: 5);

        $await = $this->subscribePriority($channel, 'notify.mail');

        $this->artisan('rabbitmq:replay-dlq', ['queue' => 'notify.mail', '--limit' => 1, '--keep-attempts' => true])->assertExitCode(0);

        $message = $await();
        $this->assertNotNull($message);

        $headers = $message->get('application_headers')->getNativeData();
        $this->assertSame(5, $headers['laravel']['attempts']);

        $channel->basic_ack($message->getDeliveryTag());
    }
}
