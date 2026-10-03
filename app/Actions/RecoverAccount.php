<?php

namespace App\Actions;

use App\Models\User;
use App\Support\SecurityEvent;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class RecoverAccount
{
    /** @param list<string> $roles */
    public function send(string $email, array $roles): void
    {
        DB::transaction(function () use ($email, $roles): void {
            User::where('email', $email)->lockForUpdate()->first();
            Password::broker('users')->sendResetLink(['email' => $email, 'role' => $roles, 'is_active' => true]);
        }, 3);
    }

    /** @param array{email: string, token: string, password: string} $data
     * @param  list<string>  $roles
     */
    public function reset(#[\SensitiveParameter] array $data, array $roles): string
    {
        return DB::transaction(function () use ($data, $roles): string {
            User::where('email', $data['email'])->lockForUpdate()->first();

            return Password::broker('users')->reset([
                'email' => $data['email'], 'role' => $roles, 'is_active' => true,
                'token' => $data['token'], 'password' => $data['password'],
            ], function (User $account, string $password): void {
                $account->password = Hash::make($password);
                $account->save();
                SecurityEvent::record('password_reset', $account->id);
                DB::afterCommit(fn () => event(new PasswordReset($account)));
            });
        }, 3);
    }
}
