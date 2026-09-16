<?php

namespace App\Models;

use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'sender_type', 'business_type', 'business_description'])]
#[Hidden(['password', 'remember_token', 'phone_otp_hash', 'pending_phone', 'phone_otp_expires_at', 'phone_otp_attempts', 'phone_otp_send_count', 'phone_otp_window_at', 'phone_otp_sent_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['sender_type' => 'business'];

    public function isStaff(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Rider], true);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime', 'phone_otp_expires_at' => 'datetime', 'phone_otp_sent_at' => 'datetime', 'phone_otp_window_at' => 'datetime', 'phone_otp_attempts' => 'integer', 'phone_otp_send_count' => 'integer', 'is_active' => 'boolean',
            'password' => 'hashed',
            'role' => UserRole::class,
            'notify_orders' => 'boolean',
            'notify_messages' => 'boolean',
        ];
    }
}
