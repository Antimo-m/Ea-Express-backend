<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chiusura definitiva degli account staff: l'utente resta nel database per ordini, contabilità e audit,
 * ma senza credenziali né dati di contatto. Colonna nullable: nessun dato esistente cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
