<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('tracking_token', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('store_name', 150);
            $table->string('contact_email')->nullable();
            $table->string('recipient_name', 150);
            $table->string('recipient_phone', 30);
            $table->string('pickup_address');
            $table->string('pickup_city', 100);
            $table->string('delivery_address');
            $table->string('delivery_city', 100);
            $table->date('pickup_date');
            $table->time('pickup_from');
            $table->time('pickup_to');
            $table->string('delivery_window', 150)->nullable();
            $table->unsignedSmallInteger('parcel_count');
            $table->string('category', 30);
            $table->string('urgency', 20)->default('standard');
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('received');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('tracking_started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('estimated_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['rider_id', 'status']);
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
