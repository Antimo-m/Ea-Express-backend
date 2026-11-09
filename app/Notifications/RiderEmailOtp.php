<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiderEmailOtp extends Notification
{
    public function __construct(#[\SensitiveParameter] public string $code) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->mailer(config('auth_security.otp.mailer'))->subject('Verifica accesso staff · EA-Express')->greeting('Verifica il tuo account')
            ->line('Il tuo codice di verifica è: '.$this->code)
            ->line('Il codice scade tra '.(int) ceil(config('auth_security.otp.ttl_seconds') / 60).' minuti. Non condividerlo con nessuno.')
            ->line('Usa il codice soltanto nella sessione in cui hai avviato l’accesso.');
    }
}
