<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingDiscountNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $promoCode = 'WELCOME15'
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Exclusive Discount')
            ->line('Use promo code: '.$this->promoCode);
    }
}
