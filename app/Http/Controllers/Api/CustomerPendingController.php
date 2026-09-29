<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerPendingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerPendingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'view' => ['nullable', 'in:all,open,history'],
            'direction' => ['nullable', 'in:incoming,outgoing'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $request->user('customer')->pendingAccounts();
        $totals = (clone $query)->whereIn('state', ['open', 'partially_paid'])->selectRaw('direction, SUM(amount_cents - settled_cents) AS remaining')->groupBy('direction')->pluck('remaining', 'direction');
        $accounts = $query
            ->when(($data['view'] ?? 'all') === 'open', fn ($q) => $q->whereIn('state', ['open', 'partially_paid']))
            ->when(($data['view'] ?? 'all') === 'history', fn ($q) => $q->whereIn('state', ['paid', 'cancelled']))
            ->when($data['direction'] ?? null, fn ($q, $direction) => $q->where('direction', $direction))
            ->latest()->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => CustomerPendingResource::collection($accounts->getCollection()),
            'totals' => ['incoming' => (int) ($totals['incoming'] ?? 0), 'outgoing' => (int) ($totals['outgoing'] ?? 0)],
            'meta' => ['current_page' => $accounts->currentPage(), 'last_page' => $accounts->lastPage()],
        ]);
    }

    public function show(Request $request, int $account): CustomerPendingResource
    {
        $pending = $request->user('customer')->pendingAccounts()->findOrFail($account);
        $pending->load(['settlements' => fn ($q) => $q->latest()->orderByDesc('id')]);

        return new CustomerPendingResource($pending);
    }
}
