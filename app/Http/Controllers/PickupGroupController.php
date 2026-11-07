<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\RecipientRisk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PickupGroupController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'regex:/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/']]);
        $today = now('Europe/Rome');
        $month = Carbon::createFromFormat('!Y-m', $data['month'] ?? $today->format('Y-m'), 'Europe/Rome')->locale('it');
        $base = $this->scheduledPickups($request);
        $dailyCounts = (clone $base)->whereBetween('pickup_date', [$month->toDateString(), $month->copy()->endOfMonth()->format('Y-m-d H:i:s')])
            ->selectRaw('DATE(pickup_date) AS scheduled_date, COUNT(*) as total')->groupByRaw('DATE(pickup_date)')->pluck('total', 'scheduled_date')
            ->map(fn (int|string $count): int => (int) $count);
        $days = [];
        $lastDay = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        for ($day = $month->copy()->startOfWeek(Carbon::MONDAY); $day->lte($lastDay); $day->addDay()) {
            $days[] = $day->isSameMonth($month) ? $day->copy() : null;
        }
        $weeks = collect($days)->chunk(7);
        $previousMonth = $month->format('Y-m') === '0001-01' ? null : $month->copy()->subMonth()->format('Y-m');
        $nextMonth = $month->format('Y-m') === '9999-12' ? null : $month->copy()->addMonth()->format('Y-m');

        return view('pickups.index', compact('month', 'today', 'dailyCounts', 'weeks', 'previousMonth', 'nextMonth'));
    }

    public function show(Request $request): View
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'regex:/^(?!0000)\d{4}-/'], 'page' => ['nullable', 'integer', 'min:1']]);
        $date = Carbon::createFromFormat('!Y-m-d', $data['date'], 'Europe/Rome')->locale('it');
        $scheduled = $this->scheduledPickups($request)->where('pickup_date', '>=', $date->toDateString())->where('pickup_date', '<', $date->copy()->addDay()->toDateString());
        $totalOrders = (clone $scheduled)->count();
        $groups = (clone $scheduled)
            ->selectRaw('customer_id, CASE WHEN customer_id IS NULL THEN id ELSE 0 END AS manual_order_id, MIN(pickup_from) AS first_pickup_time, MIN(id) AS first_order_id')
            ->groupBy('customer_id')->groupByRaw('CASE WHEN customer_id IS NULL THEN id ELSE 0 END')
            ->orderBy('first_pickup_time')->orderBy('first_order_id')->toBase()->paginate(20)->appends(['date' => $data['date']]);
        $customerIds = $groups->getCollection()->pluck('customer_id')->filter()->all();
        $manualOrderIds = $groups->getCollection()->pluck('manual_order_id')->filter()->all();
        $orders = (clone $scheduled)->where(fn (Builder $query) => $query->whereIn('customer_id', $customerIds)->orWhereIn('id', $manualOrderIds))
            ->withDisplayIdentity()->with('rider:id,slug,name')
            ->orderBy('pickup_from')->orderBy('created_at')->orderBy('id')->get();
        $ordersByAccount = $orders->groupBy(fn (Order $order): string => $this->accountKey($order->customer_id, $order->id));
        $groups->through(fn (object $group): Collection => $ordersByAccount->get($this->accountKey($group->customer_id, $group->manual_order_id), collect()));
        $recipientRisks = app(RecipientRisk::class)->forOrders($orders);

        return view('pickups.day', compact('date', 'orders', 'groups', 'totalOrders', 'recipientRisks'));
    }

    /** @return Builder<Order> */
    private function scheduledPickups(Request $request): Builder
    {
        return Order::visibleTo($request->user())->awaitingPickup();
    }

    private function accountKey(?int $customerId, int $orderId): string
    {
        return $customerId === null ? 'manual:'.$orderId : 'customer:'.$customerId;
    }
}
