<?php

namespace App\Actions;

use App\Events\WorkspaceUpdated;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcknowledgeMessages
{
    public function handle(Request $request, Order $order, bool $customer): void
    {
        $data = $request->validate(['state' => ['sometimes', 'required', 'in:delivered,read'], 'ids' => ['required_with:state', 'array', 'min:1', 'max:30'], 'ids.*' => ['required', 'uuid']]);
        DB::transaction(function () use ($data, $order, $customer): void {
            $query = $order->messages();
            $customer ? $query->whereNotNull('user_id') : $query->whereNull('user_id');
            if (isset($data['ids'])) {
                $query->whereIn('slug', $data['ids']);
            }
            $changed = (clone $query)->whereNull('delivered_at')->update(['delivered_at' => now()]);
            if (($data['state'] ?? 'read') === 'read') {
                $changed += $query->whereNull('read_at')->update(['read_at' => now()]);
            }
            if ($changed) {
                WorkspaceUpdated::dispatch($order->id, 'receipts');
            }
        });
    }
}
