<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\OrderSelection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderLabelController extends Controller
{
    public function index(Request $request): View
    {
        if ($request->filled('scope')) {
            $request->validate(['scope' => ['required', 'in:all,filtered']]);
            $orders = app(OrderSelection::class)->query($request, 'orders.in-progress', $request->input('scope') === 'filtered')->orderBy('pickup_date')->orderBy('id')->limit(1001)->get();
            abort_if($orders->count() > 1000, 422, 'Il documento supera 1000 etichette. Restringi i filtri.');

            return view('orders.labels', compact('orders'));
        }
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']]);
        $orders = Order::visibleTo($request->user())->whereIn('id', $data['ids'])->orderBy('pickup_date')->orderBy('id')->get();
        abort_unless($orders->count() === count($data['ids']), 404);
        abort_if($orders->sum('parcel_count') > 500, 422, 'Stampa al massimo 500 colli per documento.');

        return view('orders.labels', compact('orders'));
    }
}
