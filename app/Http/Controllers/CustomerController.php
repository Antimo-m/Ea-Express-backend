<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\SenderType;
use App\Support\OrderStatistics;
use App\Support\ReportingPeriod;
use App\Support\ShippingEconomics;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'sender_type' => ['nullable', Rule::enum(SenderType::class)], 'sort' => ['nullable', 'in:name,volume,value']]);
        $activity = Order::query()->selectRaw("customer_id, COUNT(*) AS orders_count, SUM(CASE WHEN status NOT IN ('cancelled','rejected') THEN COALESCE(price_cents,0) ELSE 0 END) AS shipping_cents")->groupBy('customer_id');
        $customers = User::query()->where('role', UserRole::Customer)
            ->leftJoinSub($activity, 'activity', 'activity.customer_id', '=', 'users.id')
            ->select('users.*')->selectRaw('COALESCE(activity.orders_count,0) AS orders_count, COALESCE(activity.shipping_cents,0) AS shipping_cents')
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(fn ($identity) => $identity->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')))
            ->when($filters['sender_type'] ?? null, fn ($query, $type) => $query->where('sender_type', $type));
        match ($filters['sort'] ?? 'name') {
            'volume' => $customers->orderByDesc('orders_count'),
            'value' => $customers->orderByDesc('shipping_cents'),
            default => $customers->orderBy('name'),
        };

        return view('customers.index', ['customers' => $customers->orderBy('users.id')->paginate(25)->withQueryString()]);
    }

    public function show(Request $request, User $customer, OrderStatistics $statistics): View
    {
        abort_unless($customer->role === UserRole::Customer, 404);
        $period = $statistics->staffPeriod($request);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(OrderStatus::class)], 'tariff' => ['nullable', 'integer', 'min:0']]);
        $allOrders = Order::financialFor($request->user())->where('customer_id', $customer->id);
        $query = (clone $allOrders)->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $days = (int) $period->start->copy()->startOfDay()->diffInDays($period->end->copy()->startOfDay()) + 1;
        $previousPeriod = $request->input('period') === 'year'
            ? ReportingPeriod::year($period->start->year - 1)
            : new ReportingPeriod($period->start->copy()->subDays($days), $period->start->copy()->subSecond());
        $comparison = $statistics->summarize((clone $query)->whereBetween('created_at', $previousPeriod->utcRange()), false);
        $query->whereBetween('created_at', $period->utcRange());
        $summary = $statistics->summarize(clone $query);
        $lifetime = $statistics->summarize(clone $allOrders, false);
        $trend = collect($statistics->trend(clone $query, $period));
        $grouped = $request->input('period') === 'year' ? $trend->groupBy(fn ($point) => substr($point['date'], 0, 7)) : $trend->groupBy('date');
        $trend = $grouped->map(fn ($points, $date): array => ['label' => $date, 'orders' => $points->sum('shipments'), 'incoming' => 0, 'outgoing' => 0])->values()->all();
        $pricedCount = $summary['total'] - $summary['cancelled'] - $summary['unpriced'];
        $averageCents = $pricedCount ? (int) round($summary['shipping_spend_cents'] / $pricedCount) : 0;
        $receipts = app(ShippingEconomics::class)->receipts(PaymentEntry::whereIn('order_id', (clone $allOrders)->select('orders.id'))->whereBetween('created_at', $period->utcRange()));
        $receivedCents = $receipts['retained'];
        $pendingCents = (int) $customer->pendingAccounts()->where('direction', 'incoming')->whereIn('state', ['open', 'partially_paid'])->selectRaw('COALESCE(SUM(amount_cents - settled_cents),0) AS outstanding')->value('outstanding');
        $orders = $query->withDisplayIdentity()->with('rider:id,slug,name')->when(isset($filters['tariff']), fn ($query) => $query->where('price_cents', $filters['tariff']))->latest()->orderByDesc('id')->paginate(10)->withQueryString();

        return view('customers.show', compact('customer', 'period', 'previousPeriod', 'summary', 'comparison', 'lifetime', 'averageCents', 'receivedCents', 'receipts', 'pendingCents', 'trend', 'orders'));
    }
}
