<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $t): void {
            $t->id();
            $t->string('area', 100)->nullable();
            $t->string('city', 100);
            $t->string('city_key', 100);
            $t->string('postal_code', 5)->nullable();
            $t->unsignedBigInteger('price_cents');
            $t->string('delivery_time', 100)->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('supersedes_id')->nullable()->constrained('shipping_rates')->restrictOnDelete();
            $t->string('source_reference', 255);
            $t->timestamps();
            $t->index(['city_key', 'active']);
        });
        Schema::create('shipping_price_proposals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('previous_price_cents')->nullable();
            $t->unsignedBigInteger('price_cents');
            $t->string('reason', 1000);
            $t->string('state', 20)->default('pending');
            $t->foreignId('responded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('responded_at')->nullable();
            $t->string('response_note', 1000)->nullable();
            $t->timestamps();
            $t->index(['order_id', 'state']);
        });
        Schema::create('economic_audits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('entity_type', 60);
            $t->unsignedBigInteger('entity_id');
            $t->string('action', 60);
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamps();
            $t->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('economic_audits');
        Schema::dropIfExists('shipping_price_proposals');
        Schema::dropIfExists('shipping_rates');
    }
};
