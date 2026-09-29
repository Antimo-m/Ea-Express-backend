<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_otp_hash')->nullable();
            $table->timestamp('email_otp_expires_at')->nullable();
            $table->timestamp('email_otp_sent_at')->nullable();
            $table->timestamp('email_otp_window_at')->nullable();
            $table->unsignedTinyInteger('email_otp_attempts')->default(0);
            $table->unsignedTinyInteger('email_otp_send_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn('assigned_at');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_otp_hash', 'email_otp_expires_at', 'email_otp_sent_at', 'email_otp_window_at', 'email_otp_attempts', 'email_otp_send_count']);
        });
    }
};
