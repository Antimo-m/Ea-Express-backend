<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->where('role', 'admin')->count() > 1) {
            throw new RuntimeException('Più Admin presenti: esegui app:transfer-admin con --reconcile prima di ripetere la migrazione. Nessun account è stato modificato.');
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedTinyInteger('admin_slot')->nullable()->virtualAs("CASE WHEN role = 'admin' THEN 1 ELSE NULL END");
            $table->unique('admin_slot');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['admin_slot']);
            $table->dropColumn('admin_slot');
        });
    }
};
