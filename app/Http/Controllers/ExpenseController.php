<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['description' => ['required', 'string', 'max:200'], 'amount' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'], 'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Rome')->toDateString()]]);
        $cents = Money::cents($data['amount']);
        if ($cents < 1) {
            throw ValidationException::withMessages(['amount' => 'L’importo deve essere maggiore di zero.']);
        }
        $expense = new Expense(['description' => $data['description'], 'spent_on' => $data['spent_on']]);
        $expense->amount_cents = $cents;
        $expense->user_id = $request->user()->id;
        $expense->save();

        return back()->with('status', 'Spesa registrata.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $expense = Expense::visibleTo($request->user())->findOrFail($expense->id);
        Expense::whereKey($expense->id)->whereNull('voided_at')->update(['voided_at' => now()]);

        return back()->with('status', 'Spesa annullata. La registrazione rimane nello storico.');
    }
}
