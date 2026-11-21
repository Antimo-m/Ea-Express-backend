<?php

namespace App\Http\Controllers;

use App\Actions\NotifyOrderParticipants;
use App\Actions\RecordEconomicAudit;
use App\Http\Requests\ExpenseRequest;
use App\Models\Order;
use App\Models\ReceiptDeclaration;
use App\OrderStatus;
use App\Rules\PlainText;
use App\Support\AccessGuard;
use App\Support\Money;
use App\Support\RiderOperations;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le sole operazioni economiche consentite al Rider:
 * - registrare una spesa propria (stesse regole delle spese dell'Admin: periodo contabile aperto, invio idempotente);
 * - inviare la conferma di aver ricevuto un incasso: una segnalazione per l'Admin, che resta l'unico a registrarlo.
 */
class RiderEconomicsController extends Controller
{
    public function storeExpense(ExpenseRequest $request): RedirectResponse|JsonResponse
    {
        AccessGuard::hideUnless($request->user()->role === UserRole::Rider && $request->user()->is_active);

        return app(ExpenseController::class)->store($request);
    }

    public function declareReceipt(Request $request, Order $order, RiderOperations $operations): RedirectResponse|JsonResponse
    {
        $rider = $request->user();
        AccessGuard::hideUnless($rider->role === UserRole::Rider && $rider->is_active && $operations->receiptsToConfirm($rider->id)->whereKey($order->id)->exists());
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'],
            'note' => ['nullable', 'string', 'max:300', new PlainText],
        ], ['amount.regex' => 'Inserisci un importo valido con al massimo due decimali.']);
        $cents = Money::cents($data['amount']);
        if ($cents < 1) {
            throw ValidationException::withMessages(['amount' => 'L’importo deve essere maggiore di zero.']);
        }
        DB::transaction(function () use ($rider, $order, $cents, $data): void {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            abort_if($locked->status !== OrderStatus::Delivered || $locked->paid_at || $locked->receipt_voided_at, 409, 'L’incasso di questa spedizione è già stato gestito dall’Admin.');
            abort_if(ReceiptDeclaration::where('order_id', $locked->id)->where('state', 'pending')->exists(), 409, 'Hai già inviato la conferma per questa spedizione: è in attesa dell’Admin.');
            $declaration = new ReceiptDeclaration(['amount_cents' => $cents, 'note' => $data['note'] ?? null]);
            $declaration->order_id = $locked->id;
            $declaration->rider_id = $rider->id;
            $declaration->save();
            app(RecordEconomicAudit::class)->handle($rider, $declaration, 'receipt.declared', null, $declaration->only(['order_id', 'rider_id', 'amount_cents', 'note', 'state']));
            app(NotifyOrderParticipants::class)->handle($locked, 'Incasso dichiarato dal Rider: '.Money::format($cents).' · da registrare nel Bilancio', $rider->id, requiresAdministration: true);
        }, 3);
        $message = 'Conferma inviata: l’Admin registrerà l’incasso nel Bilancio.';

        return $request->expectsJson() ? response()->json(['message' => $message]) : redirect()->route('rider-operations.mine')->with('status', $message);
    }
}
