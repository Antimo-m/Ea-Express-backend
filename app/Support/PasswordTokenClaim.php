<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Consumes a password broker token while the account row is locked, so concurrent requests cannot reuse it.
 */
class PasswordTokenClaim
{
    public static function claim(User $user, #[\SensitiveParameter] string $token, string $broker, string $message): void
    {
        $settings = config('auth.passwords.'.$broker);
        $table = DB::table($settings['table']);
        $record = (clone $table)->where('email', $user->email)->lockForUpdate()->first();
        $valid = $record && Hash::check($token, $record->token)
            && Carbon::parse($record->created_at)->addMinutes((int) $settings['expire'])->isFuture();
        if (! $valid || (clone $table)->where('email', $user->email)->where('token', $record->token)->delete() !== 1) {
            throw ValidationException::withMessages(['email' => $message]);
        }
    }
}
