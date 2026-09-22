<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('receipt_voided_at')->nullable();
            $table->foreignId('receipt_voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('receipt_void_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('receipt_voided_by');
            $table->dropColumn(['receipt_voided_at', 'receipt_void_reason']);
        });
    }
};
