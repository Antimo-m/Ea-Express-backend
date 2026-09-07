<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\OrderStatus;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $now = now('Europe/Rome')->locale('it');
        $base = Order::visibleTo($request->user());
        $incoming = (clone $base)->where('status', OrderStatus::Received);
        $active = (clone $base)->whereNotIn('status', [...OrderStatus::closed(), OrderStatus::Received->value]);
        $completed = (clone $base)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()]);

        return view('dashboard.index', [
            'metrics' => [
                ['label' => 'Ordini in entrata', 'value' => (clone $incoming)->count(), 'note' => 'In attesa di conferma', 'icon' => 'inbox', 'tone' => 'orange'],
                ['label' => 'Spedizioni in corso', 'value' => (clone $active)->count(), 'note' => 'Affidate alla tua gestione', 'icon' => 'truck', 'tone' => 'blue'],
                ['label' => 'Completati oggi', 'value' => (clone $completed)->count(), 'note' => 'Consegnati oggi', 'icon' => 'check2-circle', 'tone' => 'green'],
                ['label' => 'Importi in corso', 'value' => Money::format((int) (clone $active)->sum('price_cents')), 'note' => 'Tariffe concordate, ancora da consegnare', 'icon' => 'wallet2', 'tone' => 'orange'],
            ],
            'requests' => $incoming->latest()->orderByDesc('id')->limit(3)->get(),
            'shipments' => $active->orderBy('pickup_date')->orderBy('pickup_from')->orderBy('id')->limit(3)->get(),
            'greeting' => match (true) {
                $now->hour < 12 => 'Buongiorno', $now->hour < 18 => 'Buon pomeriggio', default => 'Buonasera'
            },
            'today' => $now->translatedFormat('l j F Y'),
        ]);
    }
}
