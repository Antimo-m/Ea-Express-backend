<?php

namespace App\Support;

use App\Rules\SafePasswordLength;
use App\Rules\UncompromisedPassword;
use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return ['bail', 'required', 'string', new SafePasswordLength, Password::min(config('auth_security.password.min')), new UncompromisedPassword];
    }
}
