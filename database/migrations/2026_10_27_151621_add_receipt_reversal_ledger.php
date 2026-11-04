<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_reversals', function (Blueprint $table) {
            $table->id();
            $table->uuid('slug')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('order_version');
            $table->bigInteger('original_amount_cents');
            $table->bigInteger('collected_cents');
            $table->bigInteger('corrected_cents');
            $table->timestamp('original_paid_at')->nullable();
            $table->foreignId('original_paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('pending_state', 30)->nullable();
            $table->string('reason', 500);
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('restore_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'order_version']);
            $table->index('created_at');
        });
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->string('kind', 30)->nullable();
            $table->bigInteger('cash_amount_cents')->nullable();
            $table->foreignId('receipt_reversal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('original_entry_id')->nullable()->constrained('payment_entries')->restrictOnDelete();
            $table->string('operation_key', 100)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_entry_id');
            $table->dropConstrainedForeignId('receipt_reversal_id');
            $table->dropUnique(['operation_key']);
            $table->dropColumn(['kind', 'cash_amount_cents', 'operation_key']);
        });
        Schema::dropIfExists('receipt_reversals');
    }
};
