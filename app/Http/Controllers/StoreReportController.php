<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\OrderStatistics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StoreReportController extends Controller
{
    public function index(Request $request, OrderStatistics $statistics): View
    {
        $period = $statistics->period($request);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(OrderStatus::class)], 'sender_type' => ['nullable', 'in:business,private,online_shop'], 'tariff' => ['nullable', 'integer', 'min:0'], 'q' => ['nullable', 'string', 'max:150']]);
        $query = Order::financialFor($request->user())->whereBetween('created_at', $period->utcRange());
        foreach (['status', 'sender_type'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['q'])) {
            $query->whereHas('customer', fn ($account) => $account->where('name', 'like', '%'.$filters['q'].'%'));
        }
        $detailOrders = null;
        $detailQuery = null;
        $stores = (clone $query)->selectRaw("customer_id, COUNT(*) AS orders_count, SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered_count, SUM(CASE WHEN status NOT IN ('cancelled','rejected') THEN COALESCE(price_cents,0) ELSE 0 END) AS total_cents")
            ->groupBy('customer_id')->orderBy('customer_id')->paginate(30)->withQueryString();
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
            $detailOrders = $detailQuery->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->whereNotNull('price_cents')->when(isset($filters['tariff']), fn ($q) => $q->where('price_cents', $filters['tariff']))->latest()->orderByDesc('id')->paginate(15, ['*'], 'detail_page')->withQueryString();
        }

        return view('stores.index', compact('detailName', 'accountPrices', 'stores', 'customers', 'period', 'detail', 'detailOrders'));
    }
}
