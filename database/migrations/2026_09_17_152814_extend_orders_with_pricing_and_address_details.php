<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            foreach (['pickup', 'delivery'] as $prefix) {
                $t->string($prefix.'_street_number', 20)->nullable();
                $t->string($prefix.'_postal_code', 5)->nullable();
            }
            $t->string('package_type', 20)->default('standard');
            $t->string('package_description', 255)->nullable();
            $t->unsignedTinyInteger('pricing_version')->default(0);
            $t->foreignId('shipping_rate_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('quoted_price_cents')->nullable();
            $t->json('rate_snapshot')->nullable();
            $t->string('price_state', 30)->default('legacy');
            $t->index(['customer_id', 'pickup_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropIndex(['customer_id', 'pickup_date', 'status']);
            $t->dropConstrainedForeignId('shipping_rate_id');
            $t->dropColumn(['pickup_street_number', 'pickup_postal_code', 'delivery_street_number', 'delivery_postal_code', 'package_type', 'package_description', 'pricing_version', 'quoted_price_cents', 'rate_snapshot', 'price_state']);
        });
    }
};
