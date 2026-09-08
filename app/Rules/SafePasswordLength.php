<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafePasswordLength implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 72 || str_contains($value, "\0")) {
            $fail('La password contiene caratteri non validi o è troppo lunga. Riduci il numero di caratteri.');
        }
    }
}
