<?php

namespace App\Http\Controllers;

use App\Actions\ReschedulePickup;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PickupScheduleController extends Controller
{
    public function update(Request $request, Order $order, ReschedulePickup $reschedule): RedirectResponse
    {
        Gate::authorize('view', $order);
        Gate::authorize('update', $order);
        $data = $request->validate([
            'pickup_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Europe/Rome')->toDateString()],
            'pickup_from' => ['required', 'date_format:H:i'], 'pickup_to' => ['required', 'date_format:H:i', 'after:pickup_from'],
            'reason' => ['required', 'string', 'max:500'], 'version' => ['required', 'integer', 'min:1'],
        ]);
        $reschedule->handle($request->user(), $order, $data);

        return redirect()->route('orders.show', $order)->with('status', 'Ritiro ripianificato. Nuovi orari e storico salvati.');
    }
}
