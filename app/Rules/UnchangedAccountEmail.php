<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects any attempt to submit an email different from the one bound to the account.
 */
class UnchangedAccountEmail implements ValidationRule
{
    public function __construct(private User $user) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value) || mb_strtolower(trim($value)) !== mb_strtolower((string) $this->user->email)) {
            $fail(User::ImmutableEmailMessage);
        }
    }
}
