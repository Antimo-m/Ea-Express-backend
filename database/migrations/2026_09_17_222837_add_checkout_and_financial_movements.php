<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('checkout_key')->nullable()->unique();
            $table->string('delivery_zone', 100)->nullable();
        });
        Schema::table('shipping_rates', function (Blueprint $table): void {
            $table->string('zone', 100)->nullable();
            $table->string('street', 255)->nullable();
        });
        Schema::create('financial_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->string('description', 500);
            $table->bigInteger('amount_cents');
            $table->date('occurred_on')->index();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('submission_key')->unique();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
        Schema::table('expenses', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_movements');
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
        Schema::table('shipping_rates', function (Blueprint $table): void {
            $table->dropColumn(['zone', 'street']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['checkout_key']);
            $table->dropColumn(['checkout_key', 'delivery_zone']);
        });
    }
};
