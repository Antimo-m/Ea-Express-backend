<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class BotVerification
{
    public function verify(Request $request, bool $risky): bool
    {
        if (! config('auth_security.bot.enabled') || (! $risky && ! config('auth_security.bot.always'))) {
            return false;
        }
        if (! config('auth_security.bot.secret_key') || ! config('auth_security.bot.site_key') || ! config('auth_security.bot.hostnames')) {
            throw new HttpException(503, 'Verifica di sicurezza temporaneamente non disponibile.');
        }
        $token = $request->input('cf-turnstile-response');
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            $this->required($request);
        }
        try {
            $response = Http::asForm()->connectTimeout(2)->timeout(config('auth_security.bot.timeout_seconds'))
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('auth_security.bot.secret_key'), 'response' => $token, 'remoteip' => $request->ip(),
                ]);
        } catch (Throwable) {
            SecurityEvent::record('bot_service_unavailable');
            throw new HttpException(503, 'Verifica di sicurezza temporaneamente non disponibile.');
        }
        if (! $response->successful()) {
            throw new HttpException(503, 'Verifica di sicurezza temporaneamente non disponibile.');
        }
        if ($response->json('success') !== true || $response->json('action') !== 'login'
            || ! in_array($response->json('hostname'), config('auth_security.bot.hostnames'), true)) {
            SecurityEvent::record('bot_verification_failed');
            $this->required($request);
        }

        return true;
    }

    private function required(Request $request): never
    {
        if ($request->hasSession()) {
            $request->session()->put('auth_bot_required', true);
        }
        $message = 'Completa la verifica di sicurezza e riprova.';
        if ($request->expectsJson() || $request->is('api/*')) {
            throw new HttpResponseException(response()->json([
                'message' => $message, 'bot_challenge' => ['site_key' => config('auth_security.bot.site_key'), 'action' => 'login'],
            ], 422));
        }
        throw new HttpResponseException(redirect()->route('login')->withErrors(['email' => $message])
            ->withInput($request->only('email'))->with('bot_challenge', true));
    }
}
