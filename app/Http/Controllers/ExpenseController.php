<?php

namespace App\Http\Controllers;

use App\Actions\RecordEconomicAudit;
use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function store(ExpenseRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $cents = Money::cents($data['amount']);
        if ($cents < 1) {
            throw ValidationException::withMessages(['amount' => 'L’importo deve essere maggiore di zero.']);
        }
        DB::transaction(function () use ($request, $data, $cents): void {
            User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! empty($data['submission_key'])) {
                $existing = Expense::where('user_id', $request->user()->id)->where('submission_key', $data['submission_key'])->first();
                if ($existing) {
                    abort_unless($existing->description === $data['description'] && $existing->amount_cents === $cents && $existing->spent_on->toDateString() === $data['spent_on'], 409, 'Questo invio è già stato registrato con altri dati. Ricarica il bilancio.');

                    return;
                }
            }
            $expense = new Expense(['description' => $data['description'], 'spent_on' => $data['spent_on']]);
            $expense->amount_cents = $cents;
            $expense->user_id = $request->user()->id;
            $expense->submission_key = $data['submission_key'] ?? null;
            $expense->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $expense, 'expense.created', null, $expense->toArray());
        }, 3);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Spesa registrata.', 'redirect' => route('balance.index')], 201);
        }

        return redirect()->route('balance.index')->with('status', 'Spesa registrata.');
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $cents = Money::cents($data['amount']);
        if ($cents < 1) {
            throw ValidationException::withMessages(['amount' => 'L’importo deve essere maggiore di zero.']);
        }
        DB::transaction(function () use ($request, $expense, $data, $cents): void {
            $entry = Expense::visibleTo($request->user())->lockForUpdate()->findOrFail($expense->id);
            abort_unless(! $entry->voided_at && ! $entry->pending_settlement_id && $entry->version === (int) $data['version'], 409, 'Spesa aggiornata, annullata o collegata a un saldo.');
            $before = $entry->toArray();
            $entry->description = $data['description'];
            $entry->spent_on = $data['spent_on'];
            $entry->amount_cents = $cents;
            $entry->version++;
            $entry->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $entry, 'expense.updated', $before, $entry->toArray());
        }, 3);

        return $request->expectsJson() ? response()->json(['message' => 'Spesa aggiornata.', 'redirect' => route('balance.index')]) : redirect()->route('balance.index')->with('status', 'Spesa aggiornata.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $expense = Expense::visibleTo($request->user())->findOrFail($expense->id);
        abort_if($expense->pending_settlement_id !== null, 409, 'Questa spesa è un saldo tracciato nei Sospesi e non può essere annullata separatamente.');
        DB::transaction(function () use ($request, $expense): void {
            $locked = Expense::visibleTo($request->user())->lockForUpdate()->findOrFail($expense->id);
            if ($locked->voided_at) {
                return;
            }
            $before = $locked->toArray();
            $locked->voided_at = now();
            $locked->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'expense.voided', $before, $locked->toArray());
        }, 3);

        return redirect()->route('balance.index')->with('status', 'Spesa annullata. La registrazione rimane nello storico.');
    }
}
