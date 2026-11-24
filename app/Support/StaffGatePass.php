<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;

/**
 * Pass del cancello NGINX del gestionale: mostra solo il login, non autentica nessuno.
 *
 * NGINX confronta il cookie in chiaro, perciò è escluso dalla cifratura (vedi bootstrap/app.php).
 * Si consegna soltanto dopo un'attivazione riuscita e si rinnova a ogni accesso staff completato;
 * senza valore configurato non si emette nulla.
 */
class StaffGatePass
{
    public const string CookieName = 'ea_staff';

    public function issue(): void
    {
        $pass = config('auth_security.staff_gate.pass');
        if (! is_string($pass) || $pass === '') {
            return;
        }
        Cookie::queue(Cookie::make(self::CookieName, $pass, (int) ceil(config('auth_security.staff_gate.ttl_seconds') / 60), '/', null,
            app()->environment('production') || (bool) config('session.secure'), true, false, 'lax'));
    }
}
