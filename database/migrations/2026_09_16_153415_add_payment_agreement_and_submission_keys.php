<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_method', 30)->nullable();
            $table->foreignId('payment_proposed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('payment_proposed_at')->nullable();
            $table->foreignId('payment_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('payment_confirmed_at')->nullable();
        });
        Schema::table('payment_entries', fn (Blueprint $table) => $table->string('method', 30)->nullable());
        Schema::table('expenses', function (Blueprint $table): void {
            $table->uuid('submission_key')->nullable();
            $table->unique(['user_id', 'submission_key']);
        });
        Schema::table('order_messages', function (Blueprint $table): void {
            $table->uuid('submission_key')->nullable();
            $table->unique(['order_id', 'submission_key']);
        });
    }

    public function down(): void
    {
        Schema::table('order_messages', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'submission_key']);
            $table->dropColumn('submission_key');
        });
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'submission_key']);
            $table->dropColumn('submission_key');
        });
        Schema::table('payment_entries', fn (Blueprint $table) => $table->dropColumn('method'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_proposed_by');
            $table->dropConstrainedForeignId('payment_confirmed_by');
            $table->dropColumn(['payment_method', 'payment_proposed_at', 'payment_confirmed_at']);
        });
    }
};
