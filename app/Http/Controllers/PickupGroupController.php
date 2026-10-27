<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\RecipientRisk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PickupGroupController extends Controller
{
    public function index(Request $request): View
    {
        if ($request->hasAny(['view', 'date', 'group'])) {
            return $this->grouped($request);
        }

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'regex:/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/']]);
        $today = now('Europe/Rome');
        $month = Carbon::createFromFormat('!Y-m', $data['month'] ?? $today->format('Y-m'), 'Europe/Rome')->locale('it');
        $base = $this->scheduledPickups($request);
        $dailyCounts = (clone $base)->whereBetween('pickup_date', [$month->toDateString(), $month->copy()->endOfMonth()->format('Y-m-d H:i:s')])
            ->selectRaw('DATE(pickup_date) AS scheduled_date, COUNT(*) as total')->groupByRaw('DATE(pickup_date)')->pluck('total', 'scheduled_date')
            ->map(fn (int|string $count): int => (int) $count);
        $overdueCount = (clone $base)->where('pickup_date', '<', $today->toDateString())->count();
        $days = [];
        $lastDay = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        for ($day = $month->copy()->startOfWeek(Carbon::MONDAY); $day->lte($lastDay); $day->addDay()) {
            $days[] = $day->isSameMonth($month) ? $day->copy() : null;
        }
        $weeks = collect($days)->chunk(7);
        $previousMonth = $month->format('Y-m') === '0001-01' ? null : $month->copy()->subMonth()->format('Y-m');
        $nextMonth = $month->format('Y-m') === '9999-12' ? null : $month->copy()->addMonth()->format('Y-m');

        return view('pickups.index', compact('month', 'today', 'dailyCounts', 'overdueCount', 'weeks', 'previousMonth', 'nextMonth'));
    }

    public function show(Request $request): View
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'regex:/^(?!0000)\d{4}-/'], 'page' => ['nullable', 'integer', 'min:1']]);
        $date = Carbon::createFromFormat('!Y-m-d', $data['date'], 'Europe/Rome')->locale('it');
        $orders = $this->scheduledPickups($request)->whereBetween('pickup_date', [$date->toDateString(), $date->copy()->endOfDay()->format('Y-m-d H:i:s')])
            ->withDisplayIdentity()->with('rider:id,slug,name')
            ->orderBy('pickup_from')->orderBy('id')->paginate(20)->appends(['date' => $data['date']]);
        $recipientRisks = app(RecipientRisk::class)->forOrders($orders->getCollection());

        return view('pickups.day', compact('date', 'orders', 'recipientRisks'));
    }

    /** @return Builder<Order> */
    private function scheduledPickups(Request $request): Builder
    {
        return Order::visibleTo($request->user())->awaitingPickup();
    }

    public function grouped(Request $request): View
    {
        $data = $request->validate(['view' => ['nullable', 'in:today,tomorrow,overdue,date'], 'date' => ['required_if:view,date', 'nullable', 'date_format:Y-m-d'], 'group' => ['nullable', 'string', 'size:64']]);
        $mode = $data['view'] ?? (isset($data['date']) ? 'date' : 'today');
        $today = now('Europe/Rome')->toDateString();
        $tomorrow = now('Europe/Rome')->addDay()->toDateString();
        $date = match ($mode) {
            'tomorrow' => $tomorrow, 'date' => $data['date'], default => $today
        };
        $base = $this->scheduledPickups($request);
        $counts = ['today' => (clone $base)->where('pickup_date', '>=', $today)->where('pickup_date', '<', $tomorrow)->count(), 'tomorrow' => (clone $base)->where('pickup_date', '>=', $tomorrow)->where('pickup_date', '<', now('Europe/Rome')->addDays(2)->toDateString())->count(), 'overdue' => (clone $base)->where('pickup_date', '<', $today)->count()];
        $query = (clone $base)->withDisplayIdentity();
        $mode === 'overdue' ? $query->where('pickup_date', '<', $today) : $query->where('pickup_date', '>=', $date)->where('pickup_date', '<', Carbon::parse($date)->addDay()->toDateString());
        $orders = $query->orderBy('pickup_date')->orderBy('pickup_from')->orderBy('id')->paginate(50)->appends($request->except('group'));
        $groups = $orders->getCollection()->groupBy(fn (Order $order) => hash('sha256', $order->customer_id ? 'customer:'.$order->customer_id : 'manual:'.$order->store_name.'|'.$order->contact_email.'|'.$order->pickup_address));
        $selected = isset($data['group']) ? $groups->get($data['group']) : null;
        abort_if(isset($data['group']) && ! $selected, 404);

        $recipientRisks = app(RecipientRisk::class)->forOrders($selected ?? collect());

        return view('pickups.grouped', compact('recipientRisks', 'groups', 'selected', 'date', 'mode', 'counts', 'orders'));
    }
}
