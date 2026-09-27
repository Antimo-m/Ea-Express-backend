<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_controls', function (Blueprint $table): void {
            $table->id();
            $table->date('closed_through')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        DB::table('accounting_controls')->insert(['id' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_controls');
    }
};
