<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_mail_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('recipient');
            $table->json('snapshot');
            $table->string('state', 20)->default('pending')->index();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 100)->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_mail_deliveries');
    }
};
