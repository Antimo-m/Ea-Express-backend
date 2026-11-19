<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Colonna calcolata con l'ordine della notifica e indici per contatore, elenco recente e raggruppamento.
 *
 * La colonna è derivata dal JSON già salvato: le notifiche esistenti e quelle future restano coerenti senza
 * modificare il modo in cui vengono scritte. L'indice morph predefinito è un prefisso dei nuovi e viene rimosso.
 */
return new class extends Migration
{
    public function up(): void
    {
        $expression = DB::connection()->getDriverName() === 'mysql'
            ? "JSON_VALUE(`data`, '$.order_id' RETURNING UNSIGNED NULL ON EMPTY NULL ON ERROR)"
            : "json_extract(data, '$.order_id')";

        Schema::table('notifications', function (Blueprint $table) use ($expression): void {
            $table->unsignedBigInteger('order_id')->nullable()->virtualAs($expression);
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'order_id'], 'notifications_inbox_unread_index');
            $table->index(['notifiable_type', 'notifiable_id', 'order_id', 'created_at', 'read_at'], 'notifications_inbox_order_index');
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_inbox_recent_index');
            $table->dropIndex(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->dropIndex('notifications_inbox_unread_index');
            $table->dropIndex('notifications_inbox_order_index');
            $table->dropIndex('notifications_inbox_recent_index');
            $table->dropColumn('order_id');
        });
    }
};
