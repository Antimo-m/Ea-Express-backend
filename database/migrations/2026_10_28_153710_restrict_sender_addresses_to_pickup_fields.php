<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sender_addresses', function (Blueprint $table): void {
            $table->string('store_name', 150)->nullable()->change();
            $table->string('sender_type', 20)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('sender_addresses')->whereNull('store_name')->update(['store_name' => '']);
        DB::table('sender_addresses')->whereNull('sender_type')->update(['sender_type' => '']);
        Schema::table('sender_addresses', function (Blueprint $table): void {
            $table->string('store_name', 150)->nullable(false)->change();
            $table->string('sender_type', 20)->nullable(false)->change();
        });
    }
};
