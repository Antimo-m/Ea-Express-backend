<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChanged extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->mailer(config('auth_security.otp.mailer'))
            ->subject('La tua password è stata cambiata · EA-Express')
            ->greeting('Password aggiornata')
            ->line('La password del tuo account EA-Express è stata appena cambiata. Per sicurezza abbiamo chiuso tutte le sessioni aperte: al prossimo accesso ti chiederemo anche il codice di verifica.')
            ->line('Se non sei stato tu, contatta subito EA-Express: qualcuno potrebbe conoscere l’accesso alla tua email.');
    }
}
