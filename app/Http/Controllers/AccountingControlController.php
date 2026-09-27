<?php

namespace App\Http\Controllers;

use App\Actions\RecordEconomicAudit;
use App\Models\AccountingControl;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingControlController extends Controller
{
    public function update(Request $request, RecordEconomicAudit $audit): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validate([
            'action' => ['required', 'in:close,reopen'], 'version' => ['required', 'integer', 'min:1'],
            'closed_through' => ['required_if:action,close', 'nullable', 'date_format:Y-m-d', 'before:'.now('Europe/Rome')->toDateString()],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($request, $data, $audit): void {
            $control = AccountingControl::lockForUpdate()->findOrFail(1);
            abort_unless($control->version === (int) $data['version'], 409, 'La chiusura è stata aggiornata. Ricarica le impostazioni.');
            $next = $data['closed_through'] ?? null;
            $previous = $control->closed_through?->toDateString();
            abort_unless($data['action'] === 'close' ? ($next && (! $previous || $next > $previous)) : ($previous && (! $next || $next < $previous)), 422, 'Seleziona una data coerente con la chiusura o riapertura richiesta.');
            $before = $control->only(['closed_through', 'version']);
            $control->closed_through = $next;
            $control->updated_by = $request->user()->id;
            $control->version++;
            $control->save();
            $audit->handle($request->user(), $control, $data['action'] === 'close' ? 'accounting.closed' : 'accounting.reopened', $before, [...$control->only(['closed_through', 'version']), 'reason' => $data['reason']]);
        }, 3);

        return back()->with('status', 'Periodo contabile aggiornato. Operazione registrata nello storico.');
    }
}
