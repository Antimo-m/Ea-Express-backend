<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivio ordini: gli indici per stato e per Rider diventano di copertura (data, pacchi, cliente, Rider),
 * così il calendario dell'archivio e gli elenchi per i filtri si calcolano senza leggere le righe.
 * I nuovi indici estendono quelli precedenti, che vengono sostituiti: il numero di indici non cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'created_at', 'parcel_count', 'customer_id', 'rider_id'], 'orders_history_index');
            $table->index(['rider_id', 'status', 'created_at', 'parcel_count', 'customer_id'], 'orders_rider_history_index');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_status_created_at_index');
            $table->dropIndex('orders_rider_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'created_at']);
            $table->index(['rider_id', 'status']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_history_index');
            $table->dropIndex('orders_rider_history_index');
        });
    }
};
