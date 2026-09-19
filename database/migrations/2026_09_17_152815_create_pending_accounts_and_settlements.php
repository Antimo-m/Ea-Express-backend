<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_accounts', function (Blueprint $t): void {
            $t->id();
            $t->string('subject', 150);
            $t->string('direction', 10);
            $t->string('description', 500);
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('settled_cents')->default(0);
            $t->foreignId('customer_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->date('occurred_on');
            $t->date('due_on')->nullable();
            $t->string('state', 20)->default('open');
            $t->timestamp('settled_at')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['direction', 'state', 'due_on']);
        });
        Schema::create('pending_settlements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('pending_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_cents');
            $t->string('method', 30);
            $t->string('note', 500);
            $t->uuid('submission_key');
            $t->timestamps();
            $t->unique(['pending_account_id', 'submission_key']);
        });
        Schema::table('payment_entries', fn (Blueprint $t) => $t->foreignId('pending_settlement_id')->nullable()->unique()->constrained()->restrictOnDelete());
        Schema::table('expenses', fn (Blueprint $t) => $t->foreignId('pending_settlement_id')->nullable()->unique()->constrained()->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('pending_settlement_id');
        });
        Schema::table('payment_entries', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('pending_settlement_id');
        });
        Schema::dropIfExists('pending_settlements');
        Schema::dropIfExists('pending_accounts');
    }
};
