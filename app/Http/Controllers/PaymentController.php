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
        $data = $request->validate(['method' => ['required_if:action,receive', 'nullable', Rule::in(array_keys(PaymentMethod::Labels))], 'action' => ['required', 'in:receive,reverse,restore'], 'note' => ['exclude_if:action,receive', 'required', 'string', 'max:500'], 'version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $order, $data, $notify): void {
            $locked = Order::financialFor($request->user())->lockForUpdate()->findOrFail($order->id);
            abort_if(PendingAccount::where('order_id', $locked->id)->where('state', '!=', 'cancelled')->exists(), 409, 'Registra i pagamenti nella sezione Sospesi per questa spedizione.');
            $receiving = $data['action'] === 'receive';
            $restoring = $data['action'] === 'restore';
            if ($locked->status !== OrderStatus::Delivered || ($receiving && $locked->price_cents === null) || $locked->version !== (int) $data['version'] || ($receiving && $locked->paid_at !== null) || ($restoring ? ! $locked->receipt_voided_at : $locked->receipt_voided_at !== null)) {
                throw ValidationException::withMessages(['action' => 'Operazione non disponibile. Ricarica il bilancio per controllare lo stato dell’incasso.']);
            }
            $before = $locked->only(['paid_at', 'paid_by', 'receipt_voided_at', 'receipt_voided_by', 'receipt_void_reason', 'version']);
            $netReceived = (int) PaymentEntry::where('order_id', $locked->id)->sum('amount_cents');
            abort_if($netReceived < 0 || ($restoring && $netReceived !== 0) || ($receiving && $netReceived !== 0), 409, 'Movimenti non coerenti: verifica lo storico prima di proseguire.');
            if ($receiving || (! $restoring && $netReceived > 0)) {
                $entry = new PaymentEntry;
                $entry->order_id = $locked->id;
                $entry->user_id = $request->user()->id;
                $entry->amount_cents = $receiving ? $locked->price_cents : -$netReceived;
                $entry->method = $receiving ? $data['method'] : $locked->payment_method;
                $entry->note = $receiving ? '' : $data['note'];
                $entry->save();
                app(RecordEconomicAudit::class)->handle($request->user(), $entry, $receiving ? 'payment.received' : 'payment.reversed', null, $entry->toArray());
            }
            if ($receiving) {
                $locked->payment_method = $data['method'];
            }
            $locked->receipt_voided_at = ! $receiving && ! $restoring ? now() : null;
            $locked->receipt_voided_by = ! $receiving && ! $restoring ? $request->user()->id : null;
            $locked->receipt_void_reason = ! $receiving && ! $restoring ? $data['note'] : null;
            $locked->paid_at = $receiving ? now() : null;
            $locked->paid_by = $receiving ? $request->user()->id : null;
            $locked->version++;
            $locked->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'receipt.'.$data['action'], $before, [...$locked->only(array_keys($before)), 'reason' => $data['note'] ?? null]);
            $notify->handle($locked, $receiving ? 'Incasso registrato' : ($restoring ? 'Incasso ripristinato: da registrare' : 'Incasso stornato'), $request->user()->id);
        }, 3);

        $message = match ($data['action']) {
            'receive' => 'Movimento registrato nel bilancio.',
            'reverse' => 'Incasso spostato negli storni.',
            'restore' => 'Incasso ripristinato tra quelli da registrare.',
        };
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => route('balance.index')]);
        }

        return redirect()->route('balance.index')->with('status', $message);
    }
}
