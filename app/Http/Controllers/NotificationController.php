<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('messages.notifications', ['notifications' => $request->user()->notifications()->latest()->orderByDesc('id')->paginate(20)]);
    }

    public function update(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();
        $order = Order::visibleTo($request->user())->find($item->data['order_id']);
        if (! $order) {
            return redirect()->route('notifications.index')->with('status', 'Notifica letta. L’ordine non è più assegnato alla tua gestione.');
        }

        return redirect()->route(($item->data['message'] ?? false) ? 'messages.show' : 'orders.show', $order);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'Notifiche segnate come lette.');
    }
}
