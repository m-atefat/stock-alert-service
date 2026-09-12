<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\NotificationStatus;
use App\Enums\Symbol;
use App\Jobs\SendNotificationJob;
use App\Models\AlertNotification;
use App\Models\PriceAlert;
use App\Notifications\PriceAlertNotification;
use App\Services\AlertClaimer;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class IdempotencyTest extends FeatureTestCase
{
    private function triggeredNotification(PriceAlert $alert): AlertNotification
    {
        $alert->forceFill(['status' => AlertStatus::Triggered, 'triggered_price' => '2001.00000000', 'triggered_at' => now()])->save();

        return AlertNotification::query()->create([
            'alert_id' => $alert->id,
            'status' => NotificationStatus::Pending,
            'attempts' => 0,
        ]);
    }

    private function mailerThatThrows(): MailerContract
    {
        $mailer = Mockery::mock(MailerContract::class);
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('smtp down'));

        return $mailer;
    }

    private function mailerThatSucceeds(): MailerContract
    {
        $mailer = Mockery::mock(MailerContract::class);
        $mailer->shouldReceive('send')->once();

        return $mailer;
    }

    #[Test]
    public function sends_exactly_one_mail_no_matter_how_many_times_the_job_runs_for_the_same_notification(): void
    {
        Notification::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $notification = $this->triggeredNotification($alert);

        for ($i = 0; $i < 3; $i++) {
            (new SendNotificationJob($notification->id))->handle();
        }

        Notification::assertSentTimes(PriceAlertNotification::class, 1);
        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
    }

    #[Test]
    public function claiming_an_alert_twice_concurrently_only_ever_creates_one_notification_row(): void
    {
        Queue::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);

        $claimer = app(AlertClaimer::class);

        $first = $claimer->recordAndDispatch($alert->id, '2001.00000000');
        $second = $claimer->recordAndDispatch($alert->id, '2001.00000000');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, AlertNotification::where('alert_id', $alert->id)->count());
        $this->assertSame(AlertStatus::Triggered, $alert->fresh()->status);
    }

    #[Test]
    public function two_attempts_then_failed(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $notification = $this->triggeredNotification($alert);

        Mail::shouldReceive('mailer')->twice()->andReturn($this->mailerThatThrows(), $this->mailerThatThrows());

        $job = new SendNotificationJob($notification->id);

        try {
            $job->handle();
            $this->fail('expected the first attempt to throw.');
        } catch (RuntimeException) {
        }

        $notification->refresh();
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertSame(1, $notification->attempts);

        try {
            $job->handle();
            $this->fail('expected the second attempt to throw.');
        } catch (RuntimeException) {
        }

        $notification->refresh();
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertSame(2, $notification->attempts);

        $job->failed(new RuntimeException('smtp down'));

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame(2, $notification->attempts);
    }

    #[Test]
    public function failed_never_becomes_sent(): void
    {
        Notification::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $alert->forceFill(['status' => AlertStatus::Triggered, 'triggered_price' => '2001.00000000', 'triggered_at' => now()])->save();

        $notification = AlertNotification::query()->create([
            'alert_id' => $alert->id,
            'status' => NotificationStatus::Failed,
            'attempts' => 2,
            'error' => 'smtp down',
        ]);

        (new SendNotificationJob($notification->id))->handle();

        Notification::assertNothingSent();
        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertSame(2, $notification->fresh()->attempts);
    }

    #[Test]
    public function a_failure_between_attempts_releases_to_pending_so_attempt_two_can_acquire(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $notification = $this->triggeredNotification($alert);

        Mail::shouldReceive('mailer')->once()->andReturn($this->mailerThatThrows());

        $job = new SendNotificationJob($notification->id);

        try {
            $job->handle();
            $this->fail('expected the first attempt to throw.');
        } catch (RuntimeException) {
        }

        $notification->refresh();
        $this->assertSame(NotificationStatus::Pending, $notification->status, 'a mid-retry failure must release the row, not strand it in Sending.');

        Mail::shouldReceive('mailer')->once()->andReturn($this->mailerThatSucceeds());

        $job->handle();

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(2, $notification->attempts);
    }

    #[Test]
    public function a_stranded_sending_row_is_marked_failed_on_the_last_attempt(): void
    {
        Notification::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $alert->forceFill(['status' => AlertStatus::Triggered, 'triggered_price' => '2001.00000000', 'triggered_at' => now()])->save();

        $notification = AlertNotification::query()->create([
            'alert_id' => $alert->id,
            'status' => NotificationStatus::Sending,
            'attempts' => 2,
        ]);

        (new SendNotificationJob($notification->id))->handle();

        Notification::assertNothingSent();
        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertNotNull($notification->error);
    }

    #[Test]
    public function a_stranded_sending_row_is_re_acquired_and_sent_while_a_retry_can_still_land(): void
    {
        Notification::fake();

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $alert->forceFill(['status' => AlertStatus::Triggered, 'triggered_price' => '2001.00000000', 'triggered_at' => now()])->save();

        $notification = AlertNotification::query()->create([
            'alert_id' => $alert->id,
            'status' => NotificationStatus::Sending,
            'attempts' => 1,
        ]);

        (new SendNotificationJob($notification->id))->handle();

        Notification::assertSentTimes(PriceAlertNotification::class, 1);
        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(2, $notification->attempts);
    }

    #[Test]
    public function a_late_failed_call_does_not_overwrite_a_sent_row(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $notification = $this->triggeredNotification($alert);
        $notification->forceFill(['status' => NotificationStatus::Sent, 'sent_at' => now()])->save();

        (new SendNotificationJob($notification->id))->failed(new RuntimeException('smtp down'));

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
    }

    #[Test]
    public function a_send_completing_after_the_row_went_terminal_does_not_resurrect_it(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $notification = $this->triggeredNotification($alert);

        Mail::shouldReceive('mailer')->once()->andReturnUsing(function () use ($notification) {
            DB::table('alert_notifications')->where('id', $notification->id)
                ->update(['status' => NotificationStatus::Failed->value]);

            return $this->mailerThatSucceeds();
        });

        $job = new SendNotificationJob($notification->id);
        $job->handle();

        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
    }

    #[Test]
    public function a_broker_redelivery_of_an_unacked_message_leaves_the_attempts_header_unchanged(): void
    {
        $channel = $this->rabbitChannel();
        $channel->queue_purge('notify.mail');

        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'target_price' => '2000.00000000',
            'direction' => AlertDirection::Above,
        ]);
        $notification = $this->triggeredNotification($alert);

        $received = [];
        $channel->basic_consume(
            'notify.mail', '', false, false, false, false,
            function (AMQPMessage $message) use (&$received, $channel) {
                $received[] = $message;

                if (count($received) === 1) {
                    $channel->basic_nack($message->getDeliveryTag(), false, true);
                }
            },
            null,
            new AMQPTable(['x-priority' => 10]),
        );

        SendNotificationJob::dispatch($notification->id);

        $deadline = microtime(true) + 10;

        try {
            while (count($received) < 2 && microtime(true) < $deadline) {
                $channel->wait(null, false, 5);
            }
        } catch (AMQPTimeoutException) {
        }

        $this->assertCount(2, $received, 'expected both the original delivery and its redelivery');
        [$firstDelivery, $secondDelivery] = $received;

        $this->assertFalse($firstDelivery->isRedelivered());
        $this->assertTrue($secondDelivery->isRedelivered());
        $this->assertSame(
            $this->laravelAttemptsHeader($firstDelivery),
            $this->laravelAttemptsHeader($secondDelivery),
            'a redelivery must not mutate the laravel.attempts header',
        );

        $channel->basic_ack($secondDelivery->getDeliveryTag());
    }

    private function laravelAttemptsHeader(AMQPMessage $message): ?int
    {
        try {
            $headers = $message->get('application_headers')->getNativeData();
        } catch (\OutOfBoundsException) {
            return null;
        }

        return $headers['laravel']['attempts'] ?? null;
    }
}
