<?php

namespace App\Http\Controllers;

use App\Actions\AcknowledgeMessages;
use App\Actions\NotifyOrderParticipants;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Rules\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function index(Request $request): View
    {
        $orders = $this->conversations($request);
        $order = $orders->first();
        $messages = $order?->messages()->with('user')->latest()->orderByDesc('id')->paginate(30);

        return view('messages.index', compact('orders', 'order', 'messages'));
    }

    public function show(Request $request, Order $order): View|JsonResponse
    {
        Gate::authorize('view', $order);

        $messages = $order->messages()->with('user')->latest()->orderByDesc('id')->paginate(30);
        if ($request->expectsJson()) {
            return response()->json(['data' => $messages->getCollection()->map(fn ($message) => $message->conversationData()), 'meta' => ['current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage()]]);
        }

        return view('messages.show', ['order' => $order, 'messages' => $messages, 'orders' => $this->conversations($request)]);
    }

    /** @return LengthAwarePaginator<int, Order> */
    private function conversations(Request $request): LengthAwarePaginator
    {
        // Un solo aggregato sui messaggi (servito dall'indice) invece di due sottoquery per ogni ordine con messaggi.
        $conversations = OrderMessage::query()->toBase()->select('order_id')
            ->selectRaw('MAX(created_at) AS messages_max_created_at, CAST(SUM(CASE WHEN user_id IS NULL AND read_at IS NULL THEN 1 ELSE 0 END) AS UNSIGNED) AS unread_count')
            ->groupBy('order_id');

        // Si ordinano solo gli identificativi; gli ordini completi si caricano per la pagina visualizzata.
        $page = Order::visibleTo($request->user())->joinSub($conversations, 'conversations', 'conversations.order_id', '=', 'orders.id')
            ->select('orders.id', 'conversations.messages_max_created_at', 'conversations.unread_count')
            ->orderByDesc('conversations.messages_max_created_at')->orderByDesc('orders.id')->paginate(15, ['*'], 'conversations_page')->withQueryString();
        $orders = Order::whereKey($page->getCollection()->modelKeys())->withDisplayIdentity()->with('latestMessage')->get()->keyBy('id');

        return $page->setCollection($page->getCollection()->map(fn (Order $row): Order => $orders[$row->id]->setRawAttributes([
            ...$orders[$row->id]->getAttributes(), 'messages_max_created_at' => $row->messages_max_created_at, 'unread_count' => $row->unread_count,
        ], true)));
    }

    public function store(Request $request, Order $order, NotifyOrderParticipants $notify): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $order);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000', new PlainText(multiline: true)], 'submission_key' => ['nullable', 'uuid']]);
        DB::transaction(function () use ($request, $order, $data, $notify) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            Gate::authorize('update', $locked);
            if (! empty($data['submission_key'])) {
                $existing = $locked->messages()->where('submission_key', $data['submission_key'])->first();
                if ($existing) {
                    abort_unless($existing->body === $data['body'] && $existing->user_id === $request->user()->id, 409, 'Invio già utilizzato. Ricarica la conversazione.');

                    return;
                }
            }
            $message = $locked->messages()->make(['body' => $data['body']]);
            $message->submission_key = $data['submission_key'] ?? null;
            $message->user_id = $request->user()->id;
            $message->save();
            $notify->handle($locked, 'Nuovo messaggio', $request->user()->id, true);
        }, 3);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Messaggio inviato.'], 201);
        }

        return redirect()->route('messages.show', $order)->with('status', 'Messaggio inviato nella conversazione.');
    }

    public function read(Request $request, Order $order, AcknowledgeMessages $acknowledge): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $order);
        $acknowledge->handle($request, $order, false);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Stato aggiornato.']);
        }

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
