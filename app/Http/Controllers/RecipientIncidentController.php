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
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', 'in:active,restored,all']]);
        $state = $filters['state'] ?? 'active';
        $activeProfiles = RecipientRiskProfile::whereHas('incidents', fn ($query) => $query->whereNull('dismissed_at'))->count();
        $totalProfiles = RecipientRiskProfile::whereHas('incidents')->count();
        $activeIncidents = RecipientIncident::whereNull('dismissed_at')->count();
        $profiles = RecipientRiskProfile::query()->whereHas('incidents')
            ->when($state === 'active', fn ($query) => $query->whereHas('incidents', fn ($incidents) => $incidents->whereNull('dismissed_at')))
            ->when($state === 'restored', fn ($query) => $query->whereDoesntHave('incidents', fn ($incidents) => $incidents->whereNull('dismissed_at')))
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->whereHas('incidents', function ($incidents) use ($term): void {
                $incidents->where(function ($search) use ($term): void {
                    $search->where('recipient->recipient_name', 'like', '%'.$term.'%')
                        ->orWhere('recipient->recipient_phone', 'like', '%'.$term.'%')
                        ->orWhere('recipient->delivery_city', 'like', '%'.$term.'%')
                        ->orWhere('recipient->delivery_address', 'like', '%'.$term.'%');
                });
            }))
            ->withCount(['incidents as active_count' => fn ($query) => $query->whereNull('dismissed_at'), 'incidents'])
            ->withMax(['incidents as last_active_at' => fn ($query) => $query->whereNull('dismissed_at')], 'occurred_at')
            ->with('latestIncident')
            ->latest('updated_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('recipient-incidents.index', compact('profiles', 'activeProfiles', 'totalProfiles', 'activeIncidents', 'state'));
    }

    public function show(Request $request, RecipientRiskProfile $profile): View
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $profile->load('latestIncident')->loadCount(['incidents as active_count' => fn ($query) => $query->whereNull('dismissed_at')]);
        abort_unless($profile->latestIncident, 404);
        $recipient = $profile->latestIncident->recipient;
        $editableIncident = $profile->incidents()->whereNull('dismissed_at')->latest('occurred_at')->orderByDesc('id')->first();
        $incidents = $profile->incidents()->with(['order' => fn ($query) => $query->withDisplayIdentity()])
            ->latest('occurred_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('recipient-incidents.show', compact('profile', 'recipient', 'editableIncident', 'incidents'));
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

        return redirect()->route('recipient-incidents.show', $incident->refresh()->recipient_risk_profile_id)
            ->with('status', 'Precedente aggiornato. I dati storici dell’ordine restano invariati.');
    }
}
