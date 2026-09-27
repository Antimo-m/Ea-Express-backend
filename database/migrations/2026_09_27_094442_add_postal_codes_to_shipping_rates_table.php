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
        Schema::table('shipping_rates', function (Blueprint $table): void {
            $table->json('postal_codes')->nullable();
            $table->boolean('territory_review_required')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table): void {
            $table->dropColumn(['postal_codes', 'territory_review_required']);
        });
    }
};
