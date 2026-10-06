<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const array Tables = ['orders', 'order_events', 'users', 'pending_accounts', 'pending_settlements', 'shipping_rates', 'recipient_risk_profiles', 'recipient_incidents', 'expenses', 'financial_movements', 'order_messages', 'payment_entries', 'shipping_price_proposals', 'accounting_controls'];

    public function up(): void
    {
        foreach (self::Tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('slug')->nullable()->unique();
            });
            DB::table($table)->whereNull('slug')->select('id')->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['slug' => (string) Str::uuid()]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::Tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['slug']);
                $blueprint->dropColumn('slug');
            });
        }
    }
};
