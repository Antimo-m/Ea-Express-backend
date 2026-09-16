<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'orders'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('sender_type', 20)->default('business');
                $table->string('business_type', 100)->nullable();
                $table->string('business_description', 500)->nullable();
            });
        }
        Schema::table('orders', fn (Blueprint $table) => $table->unsignedBigInteger('parcel_value_cents')->nullable());
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('parcel_value_cents'));
        foreach (['users', 'orders'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['sender_type', 'business_type', 'business_description']));
        }
    }
};
