<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indici per il Bilancio: storni per data di annullamento e consegne del periodo lette solo dall'indice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'receipt_voided_at'], 'orders_status_receipt_voided_index');
            $table->index(['status', 'delivered_at', 'receipt_voided_at', 'paid_at', 'price_cents', 'shipping_type', 'carrier_cost_cents'], 'orders_financial_delivered_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_status_receipt_voided_index');
            $table->dropIndex('orders_financial_delivered_index');
        });
    }
};
