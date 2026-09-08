<?php

namespace App\Http\Controllers\Api;

use App\Actions\NotifyOrderParticipants;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerMessageController extends Controller
{
    public function index(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 404);
        $messages = $order->messages()->latest()->orderByDesc('id')->paginate(30);

        return response()->json(['data' => $messages->getCollection()->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'sender' => $m->user_id ? 'courier' : 'customer', 'read_at' => $m->read_at?->toIso8601String(), 'created_at' => $m->created_at->toIso8601String()]), 'meta' => ['current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage()]]);
    }

    public function store(Request $request, Order $order, NotifyOrderParticipants $notify): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($order, $data, $notify, $request): void {
            $locked = Order::where('customer_id', $request->user()->id)->lockForUpdate()->findOrFail($order->id);
            $locked->messages()->create($data);
            $notify->handle($locked, 'Nuovo messaggio dal cliente', $request->user()->id, true);
        });

        return response()->json(['message' => 'Messaggio inviato.'], 201);
    }

    public function read(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 404);
        $order->messages()->whereNotNull('user_id')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'Messaggi letti.']);
    }
}
