<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Throwable;

class UncompromisedPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        if (in_array(mb_strtolower($value), ['password', 'password123', 'password1234', 'password12345', 'password123456', '123456789012', 'qwerty123456', 'qwertyuiop123', 'administrator', 'letmein123456'], true)) {
            $fail('Scegli una password meno comune e non compromessa.');

            return;
        }
        if (! config('auth_security.password.breach_check')) {
            return;
        }
        $hash = strtoupper(sha1($value));
        try {
            $response = Http::withHeaders(['Add-Padding' => 'true'])->connectTimeout(2)->timeout(config('auth_security.password.timeout_seconds'))
                ->get('https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5));
        } catch (Throwable) {
            $fail('Verifica della password non disponibile. Riprova tra poco.');

            return;
        }
        if (! $response->successful() || ! preg_match('/^[A-F0-9]{35}:\d+/m', $response->body())) {
            $fail('Verifica della password non disponibile. Riprova tra poco.');

            return;
        }
        foreach (explode("\n", $response->body()) as $line) {
            [$suffix, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
            if (hash_equals(substr($hash, 5), $suffix) && (int) $count > 0) {
                $fail('Questa password risulta compromessa. Scegline una diversa.');

                return;
            }
        }
    }
}
