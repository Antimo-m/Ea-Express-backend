<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->whereNull('paid_at')->whereNull('receipt_voided_at')->where('status', 'delivered')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('payment_entries')->whereColumn('payment_entries.order_id', 'orders.id'))
            ->orderBy('id')->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order): void {
                        $locked = DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
                        if ($locked->paid_at || $locked->receipt_voided_at || DB::table('pending_accounts')->where('order_id', $order->id)->where('state', '!=', 'cancelled')->exists()) {
                            return;
                        }
                        $entries = DB::table('payment_entries')->where('order_id', $order->id);
                        $last = (clone $entries)->orderByDesc('id')->first();
                        $reversed = $last && ($last->amount_cents < 0 || DB::table('economic_audits')->where('entity_type', 'payment_entries')->where('entity_id', $last->id)->where('action', 'payment.reversed')->exists());
                        if (! $reversed || (int) (clone $entries)->sum('amount_cents') !== 0) {
                            return;
                        }
                        $change = ['receipt_voided_at' => $last->created_at, 'receipt_voided_by' => $last->user_id, 'receipt_void_reason' => $last->note ?: 'Storno storico', 'version' => $locked->version + 1];
                        DB::table('orders')->where('id', $order->id)->update($change);
                        DB::table('economic_audits')->insert(['user_id' => null, 'entity_type' => 'orders', 'entity_id' => $order->id, 'action' => 'receipt.backfilled', 'before' => json_encode(['receipt_voided_at' => null, 'version' => $locked->version]), 'after' => json_encode($change), 'created_at' => now(), 'updated_at' => now()]);
                    });
                }
            });
    }

    /** Historical classification is retained; schema rollback removes the new fields. */
    public function down(): void {}
};
