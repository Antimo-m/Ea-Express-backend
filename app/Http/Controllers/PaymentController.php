<?php

namespace App\Http\Controllers;

use App\Actions\NotifyOrderParticipants;
use App\Actions\RecordEconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\OrderStatus;
use App\Support\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(Request $request, Order $order, NotifyOrderParticipants $notify): RedirectResponse|JsonResponse
    {
        abort_unless(Order::financialFor($request->user())->whereKey($order->id)->exists(), 404);
        $data = $request->validate(['method' => ['required_if:action,receive', 'nullable', Rule::in(array_keys(PaymentMethod::Labels))], 'action' => ['required', 'in:receive,reverse'], 'note' => ['exclude_unless:action,reverse', 'required', 'string', 'max:500'], 'version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $order, $data, $notify) {
            $locked = Order::financialFor($request->user())->lockForUpdate()->findOrFail($order->id);
            abort_if(PendingAccount::where('order_id', $locked->id)->where('state', '!=', 'cancelled')->exists(), 409, 'Registra i pagamenti nella sezione Sospesi per questa spedizione.');
            $receiving = $data['action'] === 'receive';
            if ($locked->status !== OrderStatus::Delivered || $locked->price_cents === null || $locked->version !== (int) $data['version'] || ($receiving && $locked->paid_at !== null) || (! $receiving && $locked->paid_at === null)) {
                throw ValidationException::withMessages(['action' => 'Operazione non disponibile. Ricarica il bilancio per controllare lo stato dell’incasso.']);
            }
            $entry = new PaymentEntry;
            $entry->order_id = $locked->id;
            $entry->user_id = $request->user()->id;
            $entry->amount_cents = $locked->price_cents * ($receiving ? 1 : -1);
            $entry->method = $data['method'] ?? $locked->payment_method;
            $entry->note = $receiving ? '' : $data['note'];
            $entry->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $entry, $receiving ? 'payment.received' : 'payment.reversed', null, $entry->toArray());
            if ($receiving && $entry->method) {
                $locked->payment_method = $entry->method;
            }
            $locked->paid_at = $receiving ? now() : null;
            $locked->paid_by = $receiving ? $request->user()->id : null;
            $locked->version++;
            $locked->save();
            $notify->handle($locked, $receiving ? 'Incasso registrato' : 'Incasso stornato', $request->user()->id);
        }, 3);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Movimento registrato nel bilancio.', 'redirect' => route('balance.index')]);
        }

        return redirect()->route('balance.index')->with('status', 'Movimento registrato nel bilancio.');
    }
}
