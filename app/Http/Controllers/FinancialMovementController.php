<?php

namespace App\Http\Controllers;

use App\Actions\RecordEconomicAudit;
use App\Http\Requests\FinancialMovementRequest;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use App\Support\OrderStatistics;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialMovementController extends Controller
{
    public function index(Request $request, OrderStatistics $statistics): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validate(['customer_id' => ['nullable', 'integer', 'min:1'], 'kind' => ['nullable', Rule::in(array_keys(FinancialMovement::Kinds))], 'state' => ['nullable', 'in:active,voided,all']]);
        $period = $statistics->period($request);
        $query = FinancialMovement::with(['customer:id,name', 'order:id,reference', 'user:id,name'])->whereDate('occurred_on', '>=', $period->start->toDateString())->whereDate('occurred_on', '<=', $period->end->toDateString())->when($data['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind));
        if (($data['state'] ?? 'active') !== 'all') {
            $query->whereNull('voided_at', not: ($data['state'] ?? 'active') === 'voided');
        }

        return response()->json($query->latest('occurred_on')->orderByDesc('id')->paginate(20)->withQueryString());
    }

    public function show(Request $request, FinancialMovement $movement): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);

        return response()->json(['data' => $movement->load(['customer:id,name', 'order:id,reference', 'user:id,name'])]);
    }

    public function store(FinancialMovementRequest $request): RedirectResponse|JsonResponse
    {
        return $this->save($request);
    }

    public function update(FinancialMovementRequest $request, FinancialMovement $movement): RedirectResponse|JsonResponse
    {
        return $this->save($request, $movement);
    }

    private function save(FinancialMovementRequest $request, ?FinancialMovement $movement = null): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $cents = Money::cents($data['amount']);
        if ($cents < 1) {
            throw ValidationException::withMessages(['amount' => 'Inserisci un importo maggiore di zero.']);
        }
        $cents *= in_array($data['kind'], ['extra_expense', 'adjustment_out']) ? -1 : 1;
        $result = DB::transaction(function () use ($request, $movement, $data, $cents): FinancialMovement {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $attributes = collect($data)->only(['kind', 'description', 'occurred_on', 'notes', 'customer_id', 'order_id'])->all();
            if (! empty($data['order_id'])) {
                $order = Order::findOrFail($data['order_id']);
                if (! empty($data['customer_id']) && $order->customer_id !== (int) $data['customer_id']) {
                    throw ValidationException::withMessages(['customer_id' => 'Il cliente non corrisponde all’ordine.']);
                }
                $attributes['customer_id'] = $order->customer_id;
            }
            $entry = $movement ? FinancialMovement::lockForUpdate()->findOrFail($movement->id) : FinancialMovement::where('submission_key', $data['submission_key'])->first();
            if ($entry && ! $movement) {
                $candidate = new FinancialMovement($attributes);
                $candidate->amount_cents = $cents;
                abort_unless($entry->user_id === $request->user()->id && collect(array_keys($attributes))->every(fn ($key) => (string) $entry->getRawOriginal($key) === (string) $candidate->getAttributes()[$key]) && $entry->amount_cents === $cents, 409, 'Invio già registrato con dati diversi.');

                return $entry;
            }
            if ($entry) {
                abort_unless(! $entry->voided_at && $entry->version === (int) $data['version'], 409, 'Voce aggiornata o annullata. Ricarica il bilancio.');
            }
            $before = $entry?->toArray();
            $entry ??= new FinancialMovement;
            $entry->fill($attributes);
            $entry->amount_cents = $cents;
            if (! $entry->exists) {
                $entry->user_id = $request->user()->id;
                $entry->submission_key = $data['submission_key'];
            } else {
                $entry->version++;
            }
            $entry->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $entry, $before ? 'movement.updated' : 'movement.created', $before, $entry->toArray());

            return $entry;
        }, 3);

        return $request->expectsJson() ? response()->json(['data' => $result, 'redirect' => route('balance.index')], $movement ? 200 : 201) : redirect()->route('balance.index')->with('status', 'Movimento salvato.');
    }

    public function destroy(FinancialMovementRequest $request, FinancialMovement $movement): RedirectResponse|JsonResponse
    {
        DB::transaction(function () use ($request, $movement): void {
            $entry = FinancialMovement::lockForUpdate()->findOrFail($movement->id);
            abort_unless($entry->version === $request->integer('version'), 409);
            if ($entry->voided_at) {
                return;
            }
            $before = $entry->toArray();
            $entry->voided_at = now();
            $entry->version++;
            $entry->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $entry, 'movement.voided', $before, $entry->toArray());
        }, 3);

        return $request->expectsJson() ? response()->json(['message' => 'Voce annullata.']) : redirect()->route('balance.index')->with('status','Voce annullata; storico conservato.');
    }
}
