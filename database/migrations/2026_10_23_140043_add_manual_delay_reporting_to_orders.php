<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('delay_reported_at')->nullable()->index();
            $table->foreignId('delay_reported_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('order_events', function (Blueprint $table): void {
            $table->string('delay_action', 20)->nullable();
            $table->index(['order_id', 'delay_action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('order_events', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'delay_action', 'created_at']);
            $table->dropColumn('delay_action');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delay_reported_by');
            $table->dropColumn('delay_reported_at');
        });
    }
};
