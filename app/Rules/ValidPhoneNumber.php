<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts Italian landline/mobile numbers and international E.164 numbers; see PhoneNumber.
 */
class ValidPhoneNumber implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PhoneNumber::isValid($value)) {
            $fail(PhoneNumber::Message);
        }
    }
}
