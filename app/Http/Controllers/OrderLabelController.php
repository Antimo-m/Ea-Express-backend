<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\OrderSelection;
use App\Support\PickupAgenda;
use App\Support\PublicReferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class OrderLabelController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->input('scope') === 'history') {
            $data = $request->validate(['day' => ['required', 'date_format:Y-m-d']]);
            $start = Carbon::parse($data['day'], 'Europe/Rome')->startOfDay();
            $orders = app(OrderSelection::class)->query($request, 'orders.history')->where('parcel_count', '>', 0)
                ->where('created_at', '>=', $start->copy()->utc())->where('created_at', '<', $start->copy()->addDay()->utc())
                ->orderBy('created_at')->orderBy('id')->limit(1001)->get();
            abort_if($orders->count() > 1000 || $orders->sum('parcel_count') > 1000, 422, 'Il documento supera 1000 etichette. Restringi i filtri.');
            if ($orders->isEmpty()) {
                return $this->empty($request);
            }

            return view('orders.labels', compact('orders'));
        }
        if ($request->input('scope') === 'pickup') {
            // Giornata di ritiro di un account nell'agenda: stessa visibilità dell'agenda (il Rider vede solo i propri ritiri).
            $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'account' => ['required', 'uuid'], 'kind' => ['required', 'in:customer,manual']]);
            $reference = $data['kind'] === 'manual' ? 'order_id' : 'customer_id';
            $referenceId = PublicReferences::ids([$reference => $data['account']])[$reference] ?? null;
            abort_unless($referenceId, 404);
            $date = Carbon::createFromFormat('!Y-m-d', $data['date'], 'Europe/Rome');
            $orders = app(PickupAgenda::class)->account($request->user(), $date, (int) $referenceId, $data['kind'])['query']
                ->where('parcel_count', '>', 0)->reorder()->orderBy('pickup_from')->orderBy('id')->limit(1001)->get();
            abort_if($orders->count() > 1000 || $orders->sum('parcel_count') > 1000, 422, 'Il documento supera 1000 etichette. Restringi i filtri.');

            return $orders->isEmpty() ? $this->empty($request) : view('orders.labels', compact('orders'));
        }
        if ($request->filled('scope')) {
            $request->validate(['scope' => ['required', 'in:all,filtered']]);
            $orders = app(OrderSelection::class)->query($request, 'orders.in-progress', $request->input('scope') === 'filtered')->where('parcel_count', '>', 0)->orderBy('pickup_date')->orderBy('id')->limit(1001)->get();
            abort_if($orders->count() > 1000 || $orders->sum('parcel_count') > 1000, 422, 'Il documento supera 1000 etichette. Restringi i filtri.');

            if ($orders->isEmpty()) {
                return $this->empty($request);
            }

            return view('orders.labels', compact('orders'));
        }
        if (! $request->has('ids') || $request->input('ids') === []) {
            return $this->empty($request);
        }
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'uuid', 'distinct']]);
        $orders = Order::visibleTo($request->user())->where('parcel_count', '>', 0)->whereIn('slug', $data['ids'])->orderBy('pickup_date')->orderBy('id')->get();
        abort_unless($orders->count() === count($data['ids']), 404);
        abort_if($orders->sum('parcel_count') > 500, 422, 'Stampa al massimo 500 colli per documento.');

        return view('orders.labels', compact('orders'));
    }

    private function empty(Request $request): RedirectResponse
    {
        abort_if($request->expectsJson(), 422, 'Al momento non ci sono ordini da stampare');

        return redirect()->route('orders.in-progress')->with('status', 'Al momento non ci sono ordini da stampare');
    }
}
