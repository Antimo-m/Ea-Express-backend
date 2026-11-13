<?php

namespace App\Models;

use App\Support\AccountSessionRevocation;
use App\Support\HasPublicSlug;
use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'email', 'password', 'sender_type', 'business_type', 'business_description'])]
#[Hidden(['auth_version', 'admin_slot', 'email_otp_hash', 'email_otp_expires_at', 'email_otp_sent_at', 'email_otp_window_at', 'email_otp_attempts', 'email_otp_send_count', 'password', 'remember_token', 'phone_otp_hash', 'pending_phone', 'phone_otp_expires_at', 'phone_otp_attempts', 'phone_otp_send_count', 'phone_otp_window_at', 'phone_otp_sent_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasPublicSlug;

    protected $attributes = ['sender_type' => 'business'];

    private bool $revokeSessionsOnSave = false;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->role === UserRole::Admin && (! $user->exists || $user->isDirty('role')) && static::where('role', UserRole::Admin)->when($user->exists, fn ($query) => $query->where('id', '!=', $user->id))->exists()) {
                throw ValidationException::withMessages(['role' => 'Esiste già un account Admin.']);
            }
            if ($user->exists && $user->getRawOriginal('role') === UserRole::Admin->value && ($user->role !== UserRole::Admin || ! $user->is_active)) {
                throw ValidationException::withMessages(['role' => 'Usa la procedura sicura di trasferimento dell’amministrazione.']);
            }
        });
        static::saving(function (User $user): void {
            if ($user->exists && ($user->revokeSessionsOnSave || $user->isDirty(['password', 'email', 'is_active', 'role', 'auth_version']) || ($user->isDirty('email_verified_at') && $user->email_verified_at === null))) {
                $user->invalidateCredentials();
            }
        });
        static::deleting(function (User $user): void {
            if ($user->role === UserRole::Admin) {
                throw ValidationException::withMessages(['role' => 'Non puoi eliminare l’Admin.']);
            }
            $user->invalidateCredentials();
        });
    }

    public function saveRevokingSessions(): bool
    {
        $this->revokeSessionsOnSave = true;
        try {
            return $this->save();
        } finally {
            $this->revokeSessionsOnSave = false;
        }
    }

    private function invalidateCredentials(): void
    {
        if ($this->isDirty('email')) {
            $this->email_verified_at = null;
        }
        app(AccountSessionRevocation::class)->revoke($this);
    }

    public function senderAddresses(): HasMany
    {
        return $this->hasMany(SenderAddress::class, 'customer_id');
    }

    public function pendingAccounts(): HasMany
    {
        return $this->hasMany(PendingAccount::class, 'customer_id');
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Rider], true);
    }

    protected function casts(): array
    {
        return [
            'email_otp_expires_at' => 'datetime', 'email_otp_sent_at' => 'datetime', 'email_otp_window_at' => 'datetime', 'email_otp_attempts' => 'integer', 'email_otp_send_count' => 'integer',
            'email_verified_at' => 'datetime', 'privacy_acknowledged_at' => 'datetime', 'terms_accepted_at' => 'datetime',
            'phone_verified_at' => 'datetime', 'is_active' => 'boolean',
            'password' => 'hashed',
            'role' => UserRole::class,
            'notify_orders' => 'boolean',
            'notify_messages' => 'boolean',
            'portal_preferences' => 'array',
        ];
    }
}
