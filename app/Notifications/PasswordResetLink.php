<?php

namespace App\Notifications;

use App\Models\User;
use App\UserRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued so the request takes the same time whether or not the account exists.
 */
class PasswordResetLink extends Notification implements ShouldBeEncrypted, ShouldQueue
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
        $query = http_build_query(['token' => $this->token, 'email' => $user->email]);
        if ($user->role === UserRole::Customer) {
            return rtrim((string) config('customer.frontend_url'), '/').'/reset-password#'.$query;
        }

        return route('password.reset', ['token' => $this->token]).'?'.http_build_query(['email' => $user->email]);
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)->mailer(config('auth_security.otp.mailer'))
            ->subject('Reimposta la password · EA-Express')
            ->greeting('Reimposta la tua password')
            ->line('Abbiamo ricevuto una richiesta di ripristino della password per il tuo account EA-Express.')
            ->action('Scegli una nuova password', $this->url($notifiable))
            ->line('Il link scade tra '.config('auth.passwords.users.expire').' minuti e funziona una sola volta. Una nuova richiesta annulla quella precedente.')
            ->line('Se non hai richiesto tu il ripristino, ignora questa email: la password resta invariata.');
    }
}
