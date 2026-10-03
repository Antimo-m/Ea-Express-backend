<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_events', function (Blueprint $table): void {
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operational_zone', 255)->nullable();
            $table->index(['created_at', 'rider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_events', function (Blueprint $table): void {
            $table->dropIndex(['created_at', 'rider_id']);
            $table->dropConstrainedForeignId('rider_id');
            $table->dropColumn('operational_zone');
        });
    }
};
