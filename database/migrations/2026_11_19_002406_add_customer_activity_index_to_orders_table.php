<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elenco clienti: numero di ordini e spesa per cliente calcolati solo dall'indice, senza leggere le righe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['customer_id', 'status', 'price_cents'], 'orders_customer_activity_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_customer_activity_index');
        });
    }
};
