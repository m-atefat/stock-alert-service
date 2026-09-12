<?php

namespace Tests\Unit;

use App\Enums\Symbol;
use App\Models\PriceAlert;
use App\Notifications\PriceAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PriceAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_is_not_should_queue(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'triggered_price' => '4000.00000000',
        ]);

        $notification = new PriceAlertNotification($alert);

        $this->assertNotInstanceOf(ShouldQueue::class, $notification);
    }

    #[Test]
    public function the_mail_renders_without_throwing(): void
    {
        $alert = PriceAlert::factory()->create([
            'symbol' => Symbol::XauUsd,
            'triggered_price' => '4000.00000000',
        ]);

        $mail = (new PriceAlertNotification($alert))->toMail($alert->user);

        $this->assertStringContainsString('XAUUSD', $mail->subject);
    }
}
