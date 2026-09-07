<?php

namespace App\Http\Controllers;

use App\Actions\NotifyOrderParticipants;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CustomerConversationController extends Controller
{
    private function order(string $token): Order
    {
        return Order::query()->where('conversation_token', $token)->where('conversation_expires_at', '>', now())->firstOrFail();
    }

    public function show(string $token): View
    {
        $order = $this->order($token);

        return view('messages.customer', ['reference' => $order->reference, 'token' => $token, 'messages' => $order->messages()->latest()->orderByDesc('id')->paginate(30)]);
    }

    public function store(Request $request, string $token, NotifyOrderParticipants $notify): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($token, $data, $notify) {
            $order = Order::query()->where('conversation_token', $token)->where('conversation_expires_at', '>', now())->lockForUpdate()->firstOrFail();
            $order->messages()->create($data);
            $notify->handle($order, 'Nuovo messaggio dal cliente', null, true);
        }, 3);

        return redirect()->route('conversation.show', $token)->with('status', 'Messaggio inviato al team EA-Express.');
    }
}
