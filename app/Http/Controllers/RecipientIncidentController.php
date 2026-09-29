<?php

namespace App\Http\Controllers;

use App\Actions\RecordEconomicAudit;
use App\Models\RecipientIncident;
use App\Models\RecipientRiskProfile;
use App\Support\RecipientIdentity;
use App\Support\RecipientRisk;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RecipientIncidentController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $profiles = RecipientRiskProfile::query()->whereHas('incidents')
            ->withCount(['incidents as active_count' => fn ($query) => $query->whereNull('dismissed_at')])
            ->with(['incidents' => fn ($query) => $query->with('order:id,reference')->latest('occurred_at')->orderByDesc('id')])
            ->latest('updated_at')->orderByDesc('id')->paginate(20);

        return view('recipient-incidents.index', compact('profiles'));
    }

    public function update(Request $request, RecipientIncident $incident, RecipientRisk $risk): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $rules = [
            'action' => ['required', 'in:correct,dismiss'],
            'version' => ['required', 'integer', 'min:1'],
            'correction_reason' => ['required', 'string', 'max:500'],
        ];
        foreach (RecipientIdentity::Fields as $field) {
            $rules[$field] = ['exclude_unless:action,correct', $field === 'delivery_province' ? 'nullable' : 'required', 'string', 'max:'.match ($field) {
                'recipient_name' => 150, 'recipient_phone' => 30, 'delivery_street_number' => 20, 'delivery_postal_code' => 5, 'delivery_city', 'delivery_province' => 100, default => 255,
            }];
        }
        $rules['delivery_postal_code'][] = 'regex:/^[0-9]{5}$/D';
        $rules['recipient_phone'][] = 'regex:/^[+0-9 ()\-]{6,30}$/D';
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $incident, $risk, $data): void {
            $locked = RecipientIncident::lockForUpdate()->findOrFail($incident->id);
            abort_unless($locked->version === (int) $data['version'] && ! $locked->dismissed_at, 409, 'Episodio già aggiornato o rimosso.');
            $before = $locked->toArray();
            if ($data['action'] === 'dismiss') {
                $locked->dismissed_at = now();
                $locked->dismissed_by = $request->user()->id;
            } else {
                $locked->fill($risk->identity($data));
            }
            $locked->correction_reason = $data['correction_reason'];
            $locked->version++;
            $locked->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'recipient_incident.'.$data['action'], $before, $locked->toArray());
        }, 3);

        return redirect()->route('recipient-incidents.index')->with('status', 'Precedente aggiornato. I dati storici dell’ordine restano invariati.');
    }
}
