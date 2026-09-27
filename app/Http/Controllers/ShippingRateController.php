<?php

namespace App\Http\Controllers;

use App\Actions\RecordEconomicAudit;
use App\Actions\ReviseShippingRate;
use App\Http\Requests\ShippingRateRequest;
use App\Models\ShippingRate;
use App\Support\Money;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ShippingRateController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'area' => ['nullable', 'string', 'max:100'], 'shipping_type' => ['nullable', 'in:regional,external']]);
        $request->validate(['state' => ['nullable', 'in:active,inactive,all,archived']]);
        $isAdmin = $request->user()->role === UserRole::Admin;
        $state = $isAdmin ? $request->input('state', 'active') : 'active';
        $query = ShippingRate::query()->when(! $isAdmin, fn ($q) => $q->where('territory_review_required', false))->when($data['shipping_type'] ?? null, fn ($q, $type) => $q->where('shipping_type', $type))->whereDoesntHave('successor');
        $query->when($state === 'archived', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'));
        if (in_array($state, ['active', 'inactive'], true)) {
            $query->where('active', $state === 'active');
        }
        $query->when($data['q'] ?? null, fn ($q, $term) => $q->where(function ($search) use ($term): void {
            $search->where('city_key', 'like', '%'.ShippingQuote::cityKey($term).'%')->orWhere('postal_code', 'like', '%'.$term.'%')->orWhere('zone', 'like', '%'.ShippingQuote::cityKey($term).'%');
        }))->when($data['area'] ?? null, fn ($q, $area) => $q->where('area', $area));
        $rates = $query->orderByDesc('is_default')->orderBy('area')->orderBy('city')->orderBy('id')->paginate(18)->withQueryString();
        if ($request->user()->role === UserRole::Customer) {
            $rates->through(fn (ShippingRate $rate) => $rate->only(['id', 'is_default', 'city', 'zone', 'street', 'area', 'postal_code', 'postal_codes', 'price_cents', 'delivery_time', 'shipping_type', 'max_weight_kg', 'max_dimension_cm', 'delivery_days_min', 'delivery_days_max']));
        }
        $areas = ShippingRate::whereNull('archived_at')->whereDoesntHave('successor')->when(! $isAdmin, fn ($q) => $q->where('active', true))->whereNotNull('area')->distinct()->orderBy('area')->pluck('area');

        return $request->expectsJson() ? response()->json(['rates' => $rates, 'areas' => $areas]) : view('rates.index', compact('rates', 'areas'));
    }

    public function history(Request $request, ShippingRate $rate): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $history = [];
        do {
            $history[] = $rate;
            $rate = $rate->predecessor;
        } while ($rate);

        return response()->json(['data' => $history]);
    }

    public function state(Request $request, ShippingRate $rate, ReviseShippingRate $revise): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $new = $revise->handle($request->user(), [...$rate->only($rate->getFillable()), 'active' => $data['active']], $rate);

        return response()->json(['data' => $new]);
    }

    public function destroy(Request $request, ShippingRate $rate, ReviseShippingRate $revise): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $new = DB::transaction(function () use ($request, $rate, $revise): ShippingRate {
            $new = $revise->handle($request->user(), [...$rate->only($rate->getFillable()), 'active' => false], $rate);
            $before = $new->toArray();
            $new->archived_at = now();
            $new->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $new, 'rate.archived', $before, $new->toArray());

            return $new;
        });

        return response()->json(['data' => $new]);
    }

    public function quote(Request $request, ShippingQuote $quote): JsonResponse
    {
        $data = $request->validate(['province' => ['nullable', 'string', 'max:100'], 'region' => ['nullable', 'string', 'max:100'], 'shipping_type' => ['nullable', 'in:regional,external'], 'weight_kg' => ['nullable', 'numeric', 'min:0.01', 'max:10000'], 'max_dimension_cm' => ['nullable', 'numeric', 'min:1', 'max:500'], 'zone' => ['nullable', 'string', 'max:100'], 'street' => ['nullable', 'string', 'max:255'], 'city' => ['required', 'string', 'max:100'], 'postal_code' => ['nullable', 'regex:/^[0-9]{5}$/D']]);

        $result = $quote->find($data['city'], $data['postal_code'] ?? null, $data['zone'] ?? null, $data['street'] ?? null, false, $data['shipping_type'] ?? 'regional', isset($data['weight_kg']) ? (float) $data['weight_kg'] : null, isset($data['max_dimension_cm']) ? (float) $data['max_dimension_cm'] : null, $data['province'] ?? null, $data['region'] ?? null);

        return response()->json($request->user()->role === UserRole::Customer ? ShippingQuote::customerData($result) : $result);
    }

    public function store(ShippingRateRequest $request, ReviseShippingRate $revise, ?ShippingRate $rate = null): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validated();
        $data['price_cents'] = Money::cents($data['price']);
        $data['carrier_cost_cents'] = ($data['shipping_type'] ?? $rate?->shipping_type ?? 'regional') === 'external' ? Money::cents((string) ($data['carrier_cost'] ?? (($rate?->carrier_cost_cents ?? 0) / 100))) : 0;
        unset($data['price'], $data['carrier_cost']);
        $new = $revise->handle($request->user(), $data, $rate);
        if ($request->expectsJson()) {
            return response()->json(['data' => $new], 201);
        }

        return redirect()->route('rates.index')->with('status', 'Nuova versione salvata. Gli ordini storici conservano il loro prezzo.');
    }
}
