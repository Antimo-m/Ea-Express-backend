<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SmsGateway
{
    public function sendOtp(string $phone, string $code): void
    {
        if (! PhoneVerification::enabled()) {
            throw new RuntimeException('Invio SMS disattivato nell’ambiente di sviluppo.');
        }
        $sid = config('sms.twilio.account_sid');
        $token = config('sms.twilio.auth_token');
        $from = config('sms.twilio.from');
        if (config('sms.driver') !== 'twilio' || ! $sid || ! $token || ! $from || ! preg_match('/^AC[0-9a-fA-F]{32}$/D', $sid)) {
            throw new RuntimeException('Servizio SMS non configurato. Contatta l’amministratore.');
        }
        try {
            $response = Http::asForm()->withBasicAuth($sid, $token)->connectTimeout(5)->timeout(10)
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Messages.json', ['To' => $phone, 'From' => $from, 'Body' => 'EA-Express: il tuo codice di verifica e '.$code.'. Valido 15 minuti. Non condividerlo.']);
        } catch (ConnectionException) {
            throw new RuntimeException('Invio SMS non riuscito. Attendi un minuto e riprova.');
        }
        if (! $response->successful() || ! $response->json('sid') || in_array($response->json('status'), ['failed', 'undelivered'], true)) {
            throw new RuntimeException('Invio SMS non riuscito. Controlla il numero o contatta l’amministratore.');
        }
    }
}
