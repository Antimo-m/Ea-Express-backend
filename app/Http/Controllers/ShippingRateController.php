<?php

namespace App\Http\Controllers;

use App\Actions\ReviseShippingRate;
use App\Http\Requests\ShippingRateRequest;
use App\Models\ShippingRate;
use App\Support\Money;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShippingRateController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'area' => ['nullable', 'string', 'max:100']]);
        $request->validate(['state' => ['nullable', 'in:active,inactive,all']]);
        $rates = ShippingRate::query()->when($request->user()->role !== UserRole::Admin || $request->input('state', 'active') !== 'all', fn ($q) => $q->where('active', $request->user()->role !== UserRole::Admin || $request->input('state', 'active') !== 'inactive'))->when($data['q'] ?? null, fn ($q, $term) => $q->where('city_key', 'like', '%'.ShippingQuote::cityKey($term).'%'))->when($data['area'] ?? null, fn ($q, $area) => $q->where('area', $area))->orderBy('area')->orderBy('city')->paginate($request->expectsJson() ? 18 : 50)->withQueryString();
        $areas = ShippingRate::where('active', true)->whereNotNull('area')->distinct()->orderBy('area')->pluck('area');

        return $request->expectsJson() ? response()->json(['rates' => $rates, 'areas' => $areas]) : view('rates.index', compact('rates', 'areas'));
    }

    public function quote(Request $request, ShippingQuote $quote): JsonResponse
    {
        $data = $request->validate(['zone' => ['nullable', 'string', 'max:100'], 'street' => ['nullable', 'string', 'max:255'], 'city' => ['required', 'string', 'max:100'], 'postal_code' => ['nullable', 'regex:/^[0-9]{5}$/D']]);

        return response()->json($quote->find($data['city'], $data['postal_code'] ?? null, $data['zone'] ?? null, $data['street'] ?? null));
    }

    public function store(ShippingRateRequest $request, ReviseShippingRate $revise, ?ShippingRate $rate = null): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validated();
        $data['price_cents'] = Money::cents($data['price']);
        unset($data['price']);
        $new = $revise->handle($request->user(), $data, $rate);
        if ($request->expectsJson()) {
            return response()->json(['data' => $new], 201);
        }

        return redirect()->route('rates.index')->with('status', 'Nuova versione salvata. Gli ordini storici conservano il loro prezzo.');
    }
}
