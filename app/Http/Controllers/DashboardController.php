<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\OrderStatus;
use App\Support\Money;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $now = now('Europe/Rome')->locale('it');
        $base = Order::visibleTo($request->user())->withDisplayIdentity();
        $incoming = (clone $base)->where('status', OrderStatus::Received);
        $active = (clone $base)->whereNotIn('status', [...OrderStatus::closed(), OrderStatus::Received->value]);
        $completed = (clone $base)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()]);

        $requests = $incoming->latest()->orderByDesc('id')->limit(3)->get();
        $shipments = $active->orderBy('pickup_date')->orderBy('pickup_from')->orderBy('id')->limit(3)->get();
        $recipientRisks = app(RecipientRisk::class)->forOrders($requests->concat($shipments));

        return view($request->user()->role === UserRole::Rider ? 'dashboard.rider' : 'dashboard.index', [
            'metrics' => [
                $request->user()->role === UserRole::Admin ? ['label' => 'Ordini in entrata', 'value' => (clone $incoming)->count(), 'note' => 'In attesa di conferma', 'icon' => 'inbox', 'tone' => 'orange'] : ['label' => 'Ritiri da effettuare', 'value' => (clone $base)->awaitingPickup()->count(), 'note' => 'Da ritirare', 'icon' => 'inbox', 'tone' => 'orange'],
                ['label' => 'Spedizioni in corso', 'value' => (clone $active)->count(), 'note' => 'Affidate alla tua gestione', 'icon' => 'truck', 'tone' => 'blue'],
                ['label' => 'Completati oggi', 'value' => (clone $completed)->count(), 'note' => 'Consegnati oggi', 'icon' => 'check2-circle', 'tone' => 'green'],
                $request->user()->role === UserRole::Rider ? ['label' => 'Consegne in uscita', 'value' => (clone $base)->where('status', OrderStatus::OutForDelivery)->count(), 'note' => 'In consegna', 'icon' => 'geo-alt', 'tone' => 'orange'] : ['label' => 'Importi in corso', 'value' => Money::format((int) (clone $active)->sum('price_cents')), 'note' => 'Tariffe concordate, ancora da consegnare', 'icon' => 'wallet2', 'tone' => 'orange'],
            ],
            'recipientRisks' => $recipientRisks, 'requests' => $requests,
            'shipments' => $shipments,
            'greeting' => match (true) {
                $now->hour < 12 => 'Buongiorno', $now->hour < 18 => 'Buon pomeriggio', default => 'Buonasera'
            },
            'today' => $now->translatedFormat('l j F Y'),
        ]);
    }
}
