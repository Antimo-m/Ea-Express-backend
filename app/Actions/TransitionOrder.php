<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\Money;
use App\Support\OrderPrice;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransitionOrder
{
    public function __construct(private NotifyOrderParticipants $notify, private RecordOrderMail $mail) {}

    /** @param array<string, mixed> $data */
    public function handle(Order $order, User $user, array $data): void
    {
        DB::transaction(function () use ($order, $user, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($user->is_active && $user->isStaff(), 403);
            $rider = null;
            if (($data['status'] ?? null) === OrderStatus::Accepted->value) {
                abort_unless($user->role === UserRole::Admin, 403);
                $rider = User::query()->where('role', UserRole::Rider)->where('is_active', true)->lockForUpdate()->find($data['rider_id'] ?? 0);
                if (! $rider) {
                    throw ValidationException::withMessages(['rider_id' => 'Seleziona un Rider attivo e disponibile.']);
                }
            }
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            Gate::forUser($user)->authorize('update', $locked);
            $next = OrderStatus::from($data['status']);
            if ($locked->version !== (int) $data['version'] || ! in_array($next, $locked->allowedTransitions(), true)) {
                throw ValidationException::withMessages(['status' => 'L’ordine è cambiato o il passaggio non è consentito. Ricarica la pagina.']);
            }
            Validator::make($data, ['estimated_at' => ['prohibited_unless:status,rescheduled', 'required_if:status,rescheduled', 'nullable', 'date_format:Y-m-d\\TH:i', 'after:'.now('Europe/Rome')->format('Y-m-d H:i:s')]])->validate();
            if (! in_array($next, [OrderStatus::Received, OrderStatus::Rejected, OrderStatus::Cancelled, OrderStatus::DeliveryIssue], true)) {
                if ($next !== OrderStatus::Accepted || $locked->pricing_version === 1) {
                    app(OrderPrice::class)->assertApproved($locked, $next === OrderStatus::Accepted, allowUnpricedLegacy: true);
                }
                if ($next !== OrderStatus::Accepted && $locked->pricing_version === 1) {
                    abort_unless($locked->rider_id !== null, 409, 'Assegna prima un Rider.');
                }
            }
            if ($locked->status === OrderStatus::Rejected && ! $locked->recoverable()) {
                throw ValidationException::withMessages(['status' => 'Il recupero è consentito soltanto entro un’ora dal rifiuto.']);
            }
            if ($locked->shipping_type === 'external' && $next === OrderStatus::Accepted) {
                abort_unless($locked->quoted_price_cents !== null, 409, 'Configura prima la tariffa fuori regione.');
            }
            if ($locked->shipping_type === 'external' && $next === OrderStatus::Delivered) {
                $locked->carrier_status = 'delivered';
            }
            if ($next === OrderStatus::Accepted) {
                $beforeAssignment = $locked->only(['rider_id', 'assigned_by', 'assigned_at']);
                $locked->rider_id = $rider->id;
                $locked->assigned_by = $user->id;
                $locked->assigned_at = now();
                app(RecordEconomicAudit::class)->handle($user, $locked, 'rider.assigned', $beforeAssignment, $locked->only(['rider_id', 'assigned_by', 'assigned_at']));
                if ($locked->pricing_version === 1) {
                    app(OrderPrice::class)->assertApproved($locked, true);
                    $before = ['price_cents' => $locked->price_cents, 'price_state' => $locked->price_state];
                    if ($locked->price_state !== 'agreed') {
                        $locked->price_cents = $locked->quoted_price_cents;
                    }
                    $locked->price_state = 'agreed';
                    app(RecordEconomicAudit::class)->handle($user, $locked, 'price.confirmed', $before, ['price_cents' => $locked->price_cents, 'price_state' => 'agreed']);
                } else {
                    $locked->price_cents = Money::cents($data['price']);
                }
            }
            if ($next === OrderStatus::Rejected) {
                $locked->rejected_at = now();
                $locked->rejected_by = $user->id;
            }
            if ($next === OrderStatus::Received) {
                $locked->rejected_at = null;
                $locked->rejected_by = null;
            }
            if ($next === OrderStatus::RiderArriving && ! $locked->tracking_started_at) {
                $locked->tracking_started_at = now();
            }
            if ($next === OrderStatus::Delivered) {
                $locked->delivered_at = now();
            }
            $locked->estimated_at = $next === OrderStatus::Rescheduled
                ? Carbon::parse($data['estimated_at'], 'Europe/Rome')->utc() : null;
            if ($next === OrderStatus::Cancelled && ($data['cancellation_reason'] ?? 'other') === 'recipient_absent') {
                if ($locked->status !== OrderStatus::DeliveryAttempted && ! $locked->events()->whereIn('status', [OrderStatus::OutForDelivery->value, OrderStatus::DeliveryAttempted->value])->exists()) {
                    throw ValidationException::withMessages(['cancellation_reason' => 'Registra prima il tentativo di consegna al destinatario.']);
                }
                app(RecipientRisk::class)->record($locked, $user);
            }
            $locked->status = $next;
            $locked->version++;
            $locked->save();
            $locked->events()->create(['user_id' => $user->id, 'status' => $next, 'note' => $data['note'] ?? null, 'public_note' => $data['public_note'] ?? null]);
            if ($next === OrderStatus::Delivered) {
                $this->mail->handle($locked, 'delivered');
            }
            $this->notify->handle($locked, $next->label(), $user->id);
        }, 3);
    }
}
