<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->date('pickup_reminded_on')->nullable();
            $table->index(['pickup_date', 'status']);
        });
        Schema::table('order_events', function (Blueprint $table): void {
            $table->json('schedule_change')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_events', fn (Blueprint $table) => $table->dropColumn('schedule_change'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['pickup_date', 'status']);
            $table->dropColumn('pickup_reminded_on');
        });
    }
};
