<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table): void {
            $table->string('shipping_type', 20)->default('regional');
            $table->string('carrier_name', 100)->nullable();
            $table->unsignedBigInteger('carrier_cost_cents')->default(0);
            $table->decimal('max_weight_kg', 8, 2)->nullable();
            $table->decimal('max_dimension_cm', 8, 2)->nullable();
            $table->unsignedSmallInteger('delivery_days_min')->nullable();
            $table->unsignedSmallInteger('delivery_days_max')->nullable();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_type', 20)->default('regional');
            $table->string('delivery_province', 100)->nullable();
            $table->string('delivery_region', 100)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->decimal('max_dimension_cm', 8, 2)->nullable();
            $table->string('carrier_name', 100)->nullable();
            $table->unsignedBigInteger('carrier_cost_cents')->default(0);
            $table->string('carrier_tracking', 100)->nullable();
            $table->string('carrier_status', 30)->nullable();
            $table->timestamp('carrier_handed_at')->nullable();
            $table->date('estimated_delivery_from')->nullable();
            $table->date('estimated_delivery_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['shipping_type', 'delivery_province', 'delivery_region', 'weight_kg', 'max_dimension_cm', 'carrier_name', 'carrier_cost_cents', 'carrier_tracking', 'carrier_status', 'carrier_handed_at', 'estimated_delivery_from', 'estimated_delivery_to']);
        });
        Schema::table('shipping_rates', function (Blueprint $table): void {
            $table->dropColumn(['shipping_type', 'carrier_name', 'carrier_cost_cents', 'max_weight_kg', 'max_dimension_cm', 'delivery_days_min', 'delivery_days_max']);
        });
    }
};
