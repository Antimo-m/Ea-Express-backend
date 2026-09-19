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
            $query->where('store_name', 'like', '%'.$filters['q'].'%');
        }
        $detailOrders = null;
        $detailQuery = null;
        $identity = "CASE WHEN customer_id IS NULL THEN store_name ELSE '' END";
        $email = "CASE WHEN customer_id IS NULL THEN COALESCE(contact_email, '') ELSE '' END";
        $stores = (clone $query)->selectRaw("customer_id, $identity AS store_identity, $email AS contact_identity, MAX(store_name) AS store_name, COUNT(*) AS orders_count, SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered_count, SUM(CASE WHEN status NOT IN ('cancelled','rejected') THEN COALESCE(price_cents,0) ELSE 0 END) AS total_cents")->groupBy('customer_id')->groupByRaw("$identity, $email")->orderBy('store_name')->paginate(30)->withQueryString();
        $customers = User::whereIn('id', $stores->pluck('customer_id')->filter())->pluck('name', 'id');
        $detail = null;
        $selection = $request->validate(['customer_id' => ['nullable', 'integer'], 'store' => ['nullable', 'string', 'max:150'], 'email' => ['nullable', 'string', 'max:255']]);
        if ($request->filled('customer_id')) {
            $detailQuery = (clone $query)->where('customer_id', $selection['customer_id']);
        } elseif ($request->filled('store')) {
            $detailQuery = (clone $query)->whereNull('customer_id')->where('store_name', $selection['store'])->whereRaw("COALESCE(contact_email, '') = ?", [$selection['email'] ?? '']);
        }

        if ($detailQuery) {
            $detail = $statistics->summarize(clone $detailQuery);
            $detailOrders = $detailQuery->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->whereNotNull('price_cents')->when(isset($filters['tariff']), fn ($q) => $q->where('price_cents', $filters['tariff']))->latest()->orderByDesc('id')->paginate(15, ['*'], 'detail_page')->withQueryString();
        }

        return view('stores.index', compact('stores', 'customers', 'period', 'detail', 'detailOrders'));
    }
}
