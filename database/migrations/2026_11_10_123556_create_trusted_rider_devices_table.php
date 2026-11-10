<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_rider_devices', function (Blueprint $table): void {
            $table->uuid('slug')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->char('credential_proof', 64);
            $table->timestamp('authorized_at');
            $table->timestamp('last_used_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_rider_devices');
    }
};
