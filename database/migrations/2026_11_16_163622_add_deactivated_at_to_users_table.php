<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records when an account was disabled. Accounts already disabled get the migration time as a
     * conservative starting point, because the real date was never stored.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
            $table->index(['is_active', 'deactivated_at']);
        });
        DB::table('users')->where('is_active', false)->whereNull('deactivated_at')->update(['deactivated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'deactivated_at']);
            $table->dropColumn('deactivated_at');
        });
    }
};
