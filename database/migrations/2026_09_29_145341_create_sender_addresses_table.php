<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('store_name', 150);
            $table->string('sender_type', 20);
            $table->string('business_type', 100)->nullable();
            $table->string('business_description', 500)->nullable();
            $table->string('pickup_address');
            $table->string('pickup_street_number', 20);
            $table->string('pickup_postal_code', 5);
            $table->string('pickup_city', 100);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_addresses');
    }
};
