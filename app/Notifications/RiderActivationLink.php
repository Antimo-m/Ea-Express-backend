<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiderActivationLink extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public string $token)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(User $user): string
    {
        return route('rider.activation', ['token' => $this->token]).'?'.http_build_query(['email' => $user->email]);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $hours = (int) (config('auth.passwords.rider_activations.expire') / 60);

        return (new MailMessage)->mailer(config('auth_security.otp.mailer'))
            ->subject('Benvenuto in EA-Express · Crea la tua password')
            ->greeting('Ciao '.$notifiable->name.',')
            ->line('L’amministrazione di EA-Express ha creato il tuo account rider. Per iniziare, scegli la tua password personale.')
            ->action('Crea la password', $this->url($notifiable))
            ->line('Il link è valido '.$hours.' ore e funziona una sola volta. Se scade, chiedi all’Admin di inviartene uno nuovo.')
            ->line('Al primo accesso ti chiederemo anche un codice di verifica inviato a questa email.');
    }
}
