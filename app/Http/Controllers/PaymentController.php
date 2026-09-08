<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\OrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(Request $request, Order $order): RedirectResponse
    {
        abort_unless(Order::financialFor($request->user())->whereKey($order->id)->exists(), 404);
        $data = $request->validate(['action' => ['required', 'in:receive,reverse'], 'note' => ['required', 'string', 'max:500'], 'version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $order, $data) {
            $locked = Order::financialFor($request->user())->lockForUpdate()->findOrFail($order->id);
            $receiving = $data['action'] === 'receive';
            if ($locked->status !== OrderStatus::Delivered || $locked->price_cents === null || $locked->version !== (int) $data['version'] || ($receiving && $locked->paid_at !== null) || (! $receiving && $locked->paid_at === null)) {
                throw ValidationException::withMessages(['action' => 'Operazione non disponibile. Ricarica il bilancio per controllare lo stato dell’incasso.']);
            }
            $entry = new PaymentEntry;
            $entry->order_id = $locked->id;
            $entry->user_id = $request->user()->id;
            $entry->amount_cents = $locked->price_cents * ($receiving ? 1 : -1);
            $entry->note = $data['note'];
            $entry->save();
            $locked->paid_at = $receiving ? now() : null;
            $locked->paid_by = $receiving ? $request->user()->id : null;
            $locked->version++;
            $locked->save();
        }, 3);

        return back()->with('status', 'Movimento registrato nel bilancio.');
    }
}
