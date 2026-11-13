<?php

namespace App\Support;

use App\Actions\RecordEconomicAudit;
use App\Models\User;
use App\Notifications\RiderActivationLink;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Riders created by the Admin choose their own password through a single-use link valid for 48 hours.
 */
class RiderActivation
{
    public const InvalidLinkMessage = 'Il link di attivazione non è valido o è scaduto. Chiedi all’Admin di inviarne uno nuovo.';

    public function __construct(private PasswordRecovery $recovery) {}

    public function send(User $rider): void
    {
        abort_unless($rider->role === UserRole::Rider && $rider->is_active, 403);
        $token = Password::broker('rider_activations')->createToken($rider);
        $rider->notify(new RiderActivationLink($token));
        SecurityEvent::record('rider_activation_sent', $rider->id);
    }

    /** @param array{email: string, token: string, password: string, password_confirmation: string} $data */
    public function activate(Request $request, array $data): User
    {
        $this->recovery->throttleSubmission($request, $data['email']);
        $email = AuthSecurityStore::email($data['email']);
        $rider = User::query()->where('email', $email)->where('role', UserRole::Rider)->where('is_active', true)->first();
        if (! $rider) {
            Hash::make($data['password']);
            SecurityEvent::record('rider_activation_rejected');
            throw ValidationException::withMessages(['email' => self::InvalidLinkMessage]);
        }

        return DB::transaction(function () use ($rider, $data): User {
            $locked = User::query()->lockForUpdate()->findOrFail($rider->id);
            PasswordTokenClaim::claim($locked, $data['token'], 'rider_activations', self::InvalidLinkMessage);
            $locked->password = Hash::make($data['password']);
            $locked->email_verified_at ??= now();
            $locked->saveRevokingSessions();
            app(RecordEconomicAudit::class)->handle(null, $locked, 'rider.activated', null, ['email_verified_at' => $locked->email_verified_at]);
            SecurityEvent::record('rider_activated', $locked->id);

            return $locked;
        }, 3);
    }

    public static function pending(User $user): bool
    {
        return $user->role === UserRole::Rider && $user->is_active
            && DB::table(config('auth.passwords.rider_activations.table'))->where('email', $user->email)->exists();
    }
}
