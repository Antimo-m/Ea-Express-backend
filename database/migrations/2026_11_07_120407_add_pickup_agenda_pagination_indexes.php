<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['customer_id', 'pickup_date', 'pickup_from', 'created_at'], 'orders_pickup_account_chronology_index');
        });
        Schema::table('order_events', function (Blueprint $table) {
            $table->index(['order_id', 'status'], 'order_events_order_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_pickup_account_chronology_index');
        });
        Schema::table('order_events', function (Blueprint $table) {
            $table->dropIndex('order_events_order_status_index');
        });
    }
};
