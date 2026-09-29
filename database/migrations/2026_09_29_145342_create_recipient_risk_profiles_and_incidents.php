<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_risk_profiles', function (Blueprint $table): void {
            $table->id();
            $table->char('identity_key', 64)->unique();
            $table->timestamps();
        });
        Schema::create('recipient_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_risk_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->char('phone_key', 64)->nullable()->index();
            $table->char('address_key', 64)->nullable()->index();
            $table->json('recipient');
            $table->string('reason', 80);
            $table->timestamp('occurred_at');
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('correction_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_incidents');
        Schema::dropIfExists('recipient_risk_profiles');
    }
};
