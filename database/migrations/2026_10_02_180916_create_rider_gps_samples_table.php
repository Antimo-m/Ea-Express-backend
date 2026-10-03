<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_gps_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->text('position');
            $table->timestamp('recorded_at');
            $table->timestamp('captured_at')->index();
            $table->index(['rider_id', 'recorded_at']);
            $table->index(['rider_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_gps_samples');
    }
};
