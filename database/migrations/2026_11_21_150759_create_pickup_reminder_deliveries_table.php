<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promemoria email dei ritiri: un invio per destinatario, giornata e tipo (sera prima o mattina),
     * e una riga per ogni ordine ricordato, unica per ordine, giornata e tipo, così nessun ordine
     * riceve lo stesso promemoria due volte e un ritiro riprogrammato riceve quello della nuova data.
     */
    public function up(): void
    {
        Schema::create('pickup_reminder_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('recipient');
            $table->string('kind', 10);
            $table->date('pickup_date');
            $table->json('snapshot');
            $table->string('state', 20)->default('pending')->index();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('pickup_reminder_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('pickup_reminder_deliveries')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->date('pickup_date');
            $table->timestamps();
            $table->unique(['order_id', 'pickup_date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_reminder_orders');
        Schema::dropIfExists('pickup_reminder_deliveries');
    }
};
