<?php

namespace App\Http\Controllers;

use App\Actions\NotifyOrderParticipants;
use App\Models\Order;
use App\OrderStatus;
use App\Support\PaymentMethod;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentAgreementController extends Controller
{
    public function update(Request $request, Order $order, NotifyOrderParticipants $notify): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $customer = $user->role === UserRole::Customer;
        abort_unless($customer ? $order->customer_id === $user->id : ($user->role === UserRole::Admin || $order->rider_id === $user->id), 404);
        $data = $request->validate(['action' => ['required', 'in:propose,confirm'], 'method' => ['required_if:action,propose', Rule::in(array_keys(PaymentMethod::Labels))], 'version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($order, $user, $customer, $data, $notify): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($customer ? $locked->customer_id === $user->id : ($user->role === UserRole::Admin || $locked->rider_id === $user->id), 404);
            abort_if($locked->paid_at || in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Rejected], true) || $locked->version !== (int) $data['version'], 409, 'Ordine aggiornato o pagamento non modificabile. Ricarica i dettagli.');
            if ($data['action'] === 'confirm') {
                $proposedByCustomer = $locked->payment_proposed_by === $locked->customer_id;
                abort_unless($locked->payment_method && ! $locked->payment_confirmed_at && $proposedByCustomer !== $customer, 409, 'La proposta deve essere confermata dall’altra parte.');
                $locked->payment_confirmed_by = $user->id;
                $locked->payment_confirmed_at = now();
                $title = 'Metodo di pagamento concordato';
            } else {
                $locked->payment_method = $data['method'];
                $locked->payment_proposed_by = $user->id;
                $locked->payment_proposed_at = now();
                $locked->payment_confirmed_by = null;
                $locked->payment_confirmed_at = null;
                $title = 'Metodo di pagamento proposto';
            }
            $locked->version++;
            $locked->save();
            $note = $title.': '.PaymentMethod::Labels[$locked->payment_method].'. L’incasso sarà registrato separatamente nel bilancio.';
            $locked->events()->create(['user_id' => $user->id, 'status' => $locked->status, 'public_note' => $note]);
            $message = $locked->messages()->make(['body' => $note]);
            $message->user_id = $customer ? null : $user->id;
            $message->save();
            $notify->handle($locked, $title, $user->id, true);
        }, 3);

        return $request->expectsJson() ? response()->json(['message' => 'Accordo aggiornato.']) : redirect()->route('orders.show', $order)->with('status', 'Accordo aggiornato.');
    }
}
