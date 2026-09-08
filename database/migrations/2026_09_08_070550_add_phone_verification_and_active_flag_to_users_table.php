<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->string('phone', 16)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('pending_phone', 16)->nullable();
            $table->string('phone_otp_hash')->nullable();
            $table->timestamp('phone_otp_expires_at')->nullable();
            $table->timestamp('phone_otp_sent_at')->nullable();
            $table->unsignedTinyInteger('phone_otp_attempts')->default(0);
            $table->timestamp('phone_otp_window_at')->nullable();
            $table->unsignedTinyInteger('phone_otp_send_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['is_active', 'phone', 'phone_verified_at', 'pending_phone', 'phone_otp_hash', 'phone_otp_expires_at', 'phone_otp_sent_at', 'phone_otp_attempts', 'phone_otp_window_at', 'phone_otp_send_count']);
        });
    }
};
