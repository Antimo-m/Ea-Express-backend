<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\OrderStatistics;
use App\Support\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StoreReportController extends Controller
{
    public function index(Request $request, OrderStatistics $statistics): View
    {
        $period = $statistics->period($request);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(OrderStatus::class)], 'sender_type' => ['nullable', 'in:business,private,online_shop'], 'tariff' => ['nullable', 'integer', 'min:0'], 'q' => ['nullable', 'string', 'max:150'], 'sort' => ['nullable', 'in:volume,value,delivered,name'], 'order' => ['nullable', 'in:asc,desc']]);
        $query = Order::financialFor($request->user());
        foreach (['status', 'sender_type'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['q'])) {
            $query->whereHas('customer', fn ($account) => $account->where(fn ($identity) => $identity->where('name', 'like', '%'.$filters['q'].'%')->orWhere('email', 'like', '%'.$filters['q'].'%')));
        }
        $days = (int) $period->start->copy()->startOfDay()->diffInDays($period->end->copy()->startOfDay()) + 1;
        $previousPeriod = new ReportingPeriod($period->start->copy()->subDays($days), $period->start->copy()->subSecond());
        $comparison = $statistics->summarize((clone $query)->whereBetween('created_at', $previousPeriod->utcRange()), false);
        $query->whereBetween('created_at', $period->utcRange());
        $trend = array_map(fn (array $point): array => ['label' => substr($point['date'], 8, 2).'/'.substr($point['date'], 5, 2), 'orders' => $point['shipments'], 'incoming' => 0, 'outgoing' => 0], $statistics->trend(clone $query, $period));
        $detailOrders = null;
        $detailQuery = null;
        $summary = $statistics->summarize(clone $query, false);
        $accountCount = (clone $query)->whereNotNull('customer_id')->distinct()->count('customer_id');
        $sort = $filters['sort'] ?? 'volume';
        $sortDirection = $filters['order'] ?? 'desc';
        $storeQuery = (clone $query)->selectRaw("customer_id, COUNT(*) AS orders_count, SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered_count, SUM(CASE WHEN status NOT IN ('cancelled','rejected') THEN COALESCE(price_cents,0) ELSE 0 END) AS total_cents")
            ->groupBy('customer_id');
        $sortColumn = ['volume' => 'orders_count', 'value' => 'total_cents', 'delivered' => 'delivered_count'][$sort] ?? null;
        if ($sortColumn) {
            $storeQuery->orderBy($sortColumn, $sortDirection);
        } else {
            $storeQuery->orderBy(User::select('name')->whereColumn('users.id', 'orders.customer_id')->limit(1), $sortDirection);
        }
        $stores = $storeQuery->orderBy('customer_id')->paginate(30)->withQueryString();
        $accountIds = $stores->pluck('customer_id')->filter()->all();
        $hasUnassigned = $stores->contains(fn ($store) => $store->customer_id === null);
        $priceQuery = (clone $query)->where(function ($selection) use ($accountIds, $hasUnassigned): void {
            $selection->whereIn('customer_id', $accountIds);
            if ($hasUnassigned) {
                $selection->orWhereNull('customer_id');
            }
        });
        $accountPrices = $statistics->prices($priceQuery, true)->groupBy(fn ($price) => $price->customer_id ?? 'unassigned');
        $customers = User::whereIn('id', $stores->pluck('customer_id')->filter())->pluck('name', 'id');
        $detail = null;
        $selection = $request->validate(['customer_id' => ['nullable', 'integer'], 'store' => ['nullable', 'string', 'max:150'], 'email' => ['nullable', 'string', 'max:255'], 'unassigned' => ['nullable', 'boolean']]);
        if ($request->filled('customer_id')) {
            $detailQuery = (clone $query)->where('customer_id', $selection['customer_id']);
        } elseif ($request->boolean('unassigned') || $request->filled('store')) {
            $detailQuery = (clone $query)->whereNull('customer_id');
        }

        $detailName = 'Senza account associato';
        if ($detailQuery) {
            $detailName = $request->filled('customer_id') ? ((clone $detailQuery)->with('customer')->first()?->customer?->name ?? 'Account selezionato') : $detailName;
            $detail = $statistics->summarize(clone $detailQuery);
            $detailOrders = $detailQuery->withDisplayIdentity()->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->whereNotNull('price_cents')->when(isset($filters['tariff']), fn ($q) => $q->where('price_cents', $filters['tariff']))->latest()->orderByDesc('id')->paginate(15, ['*'], 'detail_page')->withQueryString();
        }

        return view('stores.index', compact('comparison', 'previousPeriod', 'trend', 'detailName', 'accountPrices', 'stores', 'customers', 'period', 'detail', 'detailOrders', 'summary', 'accountCount'));
    }
}
