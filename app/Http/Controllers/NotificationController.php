<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\NotificationInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('messages.notifications', ['groups' => app(NotificationInbox::class)->groups($request->user())]);
    }

    public function feed(Request $request, NotificationInbox $inbox): JsonResponse
    {
        return response()->json($inbox->feed($request->user()));
    }

    public function history(Request $request, int $orderId, NotificationInbox $inbox): JsonResponse
    {
        return response()->json($inbox->history($request->user(), $orderId));
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
