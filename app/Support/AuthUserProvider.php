<?php

namespace App\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class AuthUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $user = parent::retrieveByCredentials($credentials);
        if ($user === null && is_string($credentials['password'] ?? null)) {
            $this->hasher->check($credentials['password'], config('auth_security.dummy_password_hash'));
        }

        return $user;
    }
}
