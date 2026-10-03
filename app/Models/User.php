<?php

namespace App\Models;

use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'sender_type', 'business_type', 'business_description'])]
#[Hidden(['email_otp_hash', 'email_otp_expires_at', 'email_otp_sent_at', 'email_otp_window_at', 'email_otp_attempts', 'email_otp_send_count', 'password', 'remember_token', 'phone_otp_hash', 'pending_phone', 'phone_otp_expires_at', 'phone_otp_attempts', 'phone_otp_send_count', 'phone_otp_window_at', 'phone_otp_sent_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['sender_type' => 'business'];

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty(['password', 'email', 'is_active', 'role'])) {
                $user->invalidateCredentials();
            }
        });
        static::deleting(fn (User $user) => $user->invalidateCredentials());
    }

    private function invalidateCredentials(): void
    {
        if ($this->isDirty('email')) {
            $previous = clone $this;
            $previous->email = $this->getRawOriginal('email');
            Password::broker('users')->deleteToken($previous);
            $this->email_verified_at = null;
        }
        Password::broker('users')->deleteToken($this);
        $this->remember_token = Str::random(60);
        $this->email_otp_hash = null;
        $this->email_otp_expires_at = null;
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $this->id)->delete();
        }
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

    public function canOperateDeliveries(): bool
    {
        return $this->is_active && ($this->role === UserRole::Admin || ($this->role === UserRole::Rider && $this->email_verified_at !== null));
    }

    protected function casts(): array
    {
        return [
            'email_otp_expires_at' => 'datetime', 'email_otp_sent_at' => 'datetime', 'email_otp_window_at' => 'datetime', 'email_otp_attempts' => 'integer', 'email_otp_send_count' => 'integer',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime', 'phone_otp_expires_at' => 'datetime', 'phone_otp_sent_at' => 'datetime', 'phone_otp_window_at' => 'datetime', 'phone_otp_attempts' => 'integer', 'phone_otp_send_count' => 'integer', 'is_active' => 'boolean',
            'password' => 'hashed',
            'role' => UserRole::class,
            'notify_orders' => 'boolean',
            'notify_messages' => 'boolean',
        ];
    }
}
