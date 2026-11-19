<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data di ritiro precedente alle ripianificazioni, derivata dal JSON e indicizzata: lo storico dei ritiri spostati
 * non scorre più tutti gli eventi degli ordini leggendo il JSON riga per riga.
 */
return new class extends Migration
{
    public function up(): void
    {
        $expression = DB::connection()->getDriverName() === 'mysql'
            ? "JSON_VALUE(`schedule_change`, '$.before.pickup_date' RETURNING DATE NULL ON EMPTY NULL ON ERROR)"
            : "json_extract(schedule_change, '$.before.pickup_date')";

        Schema::table('order_events', function (Blueprint $table) use ($expression): void {
            $table->date('rescheduled_from')->nullable()->virtualAs($expression);
            $table->index(['rescheduled_from', 'id'], 'order_events_rescheduled_from_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_events', function (Blueprint $table): void {
            $table->dropIndex('order_events_rescheduled_from_index');
            $table->dropColumn('rescheduled_from');
        });
    }
};
