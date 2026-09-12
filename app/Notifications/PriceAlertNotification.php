<?php

namespace App\Notifications;

use App\Models\PriceAlert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PriceAlertNotification extends Notification
{
    public function __construct(
        private readonly PriceAlert $alert,
    ) {}

    /**
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Price alert: {$this->alert->symbol->value} reached {$this->alert->triggered_price}")
            ->markdown('emails.price-alert', ['alert' => $this->alert]);
    }
}
