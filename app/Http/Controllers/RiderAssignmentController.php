<?php

namespace App\Http\Controllers;

use App\Actions\NotifyOrderParticipants;
use App\Actions\RecordEconomicAudit;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\OperationalAssignees;
use App\Support\RiderOperations;
use App\Support\RiderTracking;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiderAssignmentController extends Controller
{
    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['rider_id' => ['required', 'integer', app(OperationalAssignees::class)->rule($request->user())], 'version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $order, $data): void {
            $admin = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($admin->is_active && $admin->role === UserRole::Admin, 403);
            $rider = app(OperationalAssignees::class)->query($admin)->lockForUpdate()->find($data['rider_id']);
            if (! $rider) {
                throw ValidationException::withMessages(['rider_id' => 'Seleziona un Rider attivo e disponibile.']);
            }
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->version === (int) $data['version'], 409, 'Ordine aggiornato. Ricarica la pagina.');
            abort_if(in_array($locked->status->value, [...OrderStatus::closed(), OrderStatus::Received->value], true), 409, 'Assegna il Rider durante la presa in carico. Gli ordini chiusi conservano lo storico.');
            if ($locked->rider_id === $rider->id) {
                return;
            }
            $before = $locked->only(['rider_id', 'assigned_by', 'assigned_at']);
            app(RiderTracking::class)->stop($locked);
            $locked->rider_id = $rider->id;
            $locked->assigned_by = $admin->id;
            $locked->assigned_at = now();
            $locked->version++;
            $locked->save();
            $locked->events()->create(['rider_id' => $locked->rider_id, 'operational_zone' => app(RiderOperations::class)->zone($locked), 'user_id' => $admin->id, 'status' => $locked->status, 'note' => 'Rider assegnato: '.$rider->name]);
            app(RecordEconomicAudit::class)->handle($admin, $locked, 'rider.assigned', $before, $locked->only(['rider_id', 'assigned_by', 'assigned_at']));
            app(NotifyOrderParticipants::class)->handle($locked, 'Rider assegnato', $admin->id);
        }, 3);

        return back()->with('status', 'Assegnazione aggiornata.');
    }
}
