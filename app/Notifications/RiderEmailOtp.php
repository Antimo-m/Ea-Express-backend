<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiderEmailOtp extends Notification
{
    public function __construct(public string $code) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Verifica email Rider · EA Express')->greeting('Verifica il tuo account')
            ->line('Il tuo codice di verifica è: '.$this->code)
            ->line('Il codice scade tra 15 minuti. Non condividerlo con nessuno.')
            ->line('La verifica è richiesta una sola volta per questo indirizzo email.');
    }
}
