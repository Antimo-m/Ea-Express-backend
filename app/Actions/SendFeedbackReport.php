<?php

namespace App\Actions;

use App\Mail\FeedbackReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Invia subito (senza coda) la segnalazione all'indirizzo aziendale: gli allegati restano file temporanei
 * della richiesta, quindi la mail deve partire prima che la richiesta termini. Nulla viene salvato nel database.
 */
class SendFeedbackReport
{
    public const Unavailable = 'Il servizio segnalazioni non è disponibile in questo momento. Riprova più tardi.';

    /**
     * @param  array{category: string, subject: string, message: string, location?: string|null, client?: string|null}  $data
     * @param  list<UploadedFile>  $attachments
     */
    public function handle(User $reporter, string $source, array $data, array $attachments): bool
    {
        $recipient = config('mail.feedback_address');
        if (! filled($recipient)) {
            return false;
        }
        try {
            Mail::to($recipient)->send(new FeedbackReport($reporter, $source, $data, $attachments));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }
}
