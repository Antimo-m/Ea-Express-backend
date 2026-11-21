<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conferme d'incasso inviate dal Rider: una segnalazione operativa per l'Admin, non un movimento contabile.
     * L'incasso effettivo resta registrato solo dall'Admin nel Bilancio; alla registrazione la conferma passa a «confirmed».
     */
    public function up(): void
    {
        Schema::create('receipt_declarations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('slug')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rider_id')->constrained('users');
            $table->unsignedInteger('amount_cents');
            $table->string('note', 300)->nullable();
            $table->string('state', 20)->default('pending');
            $table->foreignId('handled_by')->nullable()->constrained('users');
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['rider_id', 'state', 'created_at']);
            $table->index(['order_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_declarations');
    }
};
