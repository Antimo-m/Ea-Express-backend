<?php

namespace App\Http\Controllers;

use App\Actions\NotifyOrderParticipants;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function index(Request $request): View
    {
        return view('messages.index', ['orders' => Order::visibleTo($request->user())->whereHas('messages')->withMax('messages', 'created_at')->withCount(['messages as unread_count' => fn ($q) => $q->whereNull('user_id')->whereNull('read_at')])->orderByDesc('messages_max_created_at')->orderByDesc('id')->paginate(15)]);
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        return view('messages.show', ['order' => $order, 'messages' => $order->messages()->with('user')->latest()->orderByDesc('id')->paginate(30)]);
    }

    public function store(Request $request, Order $order, NotifyOrderParticipants $notify): RedirectResponse
    {
        Gate::authorize('update', $order);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $order, $data, $notify) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            Gate::authorize('update', $locked);
            $message = $locked->messages()->make($data);
            $message->user_id = $request->user()->id;
            $message->save();
            $notify->handle($locked, 'Nuovo messaggio', $request->user()->id, true);
        }, 3);

        return redirect()->route('messages.show', $order)->with('status', 'Messaggio inviato nella conversazione.');
    }

    public function read(Order $order): RedirectResponse
    {
        Gate::authorize('update', $order);
        $order->messages()->whereNull('user_id')->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Messaggi segnati come letti dal team.');
    }

    public function share(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize('update', $order);
        $data = $request->validate(['action' => ['required', 'in:renew,revoke']]);
        DB::transaction(function () use ($order, $data) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            Gate::authorize('update', $locked);
            $locked->conversation_token = $data['action'] === 'renew' ? Str::random(64) : null;
            $locked->conversation_expires_at = $data['action'] === 'renew' ? now()->addDays(30) : null;
            $locked->save();
        });

        return back()->with('status', $data['action'] === 'renew' ? 'Nuovo link creato. Il precedente non è più valido.' : 'Accesso cliente revocato.');
    }
}
