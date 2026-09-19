<?php

namespace App\Http\Controllers;

use App\Actions\AcknowledgeMessages;
use App\Actions\NotifyOrderParticipants;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
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

    public function show(Request $request, Order $order): View|JsonResponse
    {
        Gate::authorize('view', $order);

        $messages = $order->messages()->with('user')->latest()->orderByDesc('id')->paginate(30);
        if ($request->expectsJson()) {
            return response()->json(['data' => $messages->getCollection()->map(fn ($message) => $message->conversationData()), 'meta' => ['current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage()]]);
        }

        return view('messages.show', ['order' => $order, 'messages' => $messages]);
    }

    public function store(Request $request, Order $order, NotifyOrderParticipants $notify): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $order);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'submission_key' => ['nullable', 'uuid']]);
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
