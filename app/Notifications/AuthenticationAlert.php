<?php

namespace App\Notifications;

use App\UserRole;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthenticationAlert extends Notification
{
    public function __construct(public string $reason, public string $requestId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->is_active && $notifiable->role === UserRole::Admin ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('EA-Express · Avviso sicurezza accessi')
            ->line('È stata rilevata attività anomala nelle richieste di autenticazione.')
            ->line('Indicatore: '.$this->reason)->line('Riferimento nei log protetti: '.$this->requestId)
            ->line('Consulta i log di sicurezza per valutare l’evento.');
    }
}
