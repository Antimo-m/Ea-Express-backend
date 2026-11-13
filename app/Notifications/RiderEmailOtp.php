<?php

namespace App\Notifications;

use App\Models\User;
use App\UserRole;
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
        $customer = $notifiable instanceof User && $notifiable->role === UserRole::Customer;

        return (new MailMessage)->mailer(config('auth_security.otp.mailer'))->subject($customer ? 'Codice di accesso al portale · EA-Express' : 'Verifica accesso staff · EA-Express')->greeting($customer ? 'Conferma il tuo accesso' : 'Verifica il tuo account')
            ->line('Il tuo codice di verifica è: '.$this->code)
            ->line('Il codice scade tra '.(int) ceil(config('auth_security.otp.ttl_seconds') / 60).' minuti. Non condividerlo con nessuno.')
            ->line('Usa il codice soltanto nella sessione in cui hai avviato l’accesso.')
            ->lineIf($customer, 'Dopo la conferma, su questo dispositivo non ti chiederemo il codice ai prossimi accessi.');
    }
}
