<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\DeliveryDeadline;
use App\Support\ReportingPeriod;
use App\Support\RiderOperations;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class RiderOperationsController extends Controller
{
    private function period(Request $request): ReportingPeriod
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'], 'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'], 'period' => ['nullable', 'in:today,week,month,custom'], 'view' => ['nullable', 'in:zone,rider'], 'zone' => ['nullable', 'string', 'max:255'], 'rider' => ['nullable', 'uuid', 'exists:users,slug'], 'scope' => ['nullable', 'in:programmed,late'], 'q' => ['nullable', 'string', 'max:255'], 'filter' => ['nullable', 'in:all,open,late'], 'sort' => ['nullable', 'in:name,late,volume']], ['to.after_or_equal' => 'La data finale deve essere uguale o successiva alla data iniziale.']);
        $mode = $data['period'] ?? (isset($data['date']) || isset($data['from']) ? 'custom' : 'today');
        if ($mode === 'custom' && isset($data['from'], $data['to'])) {
            return new ReportingPeriod(Carbon::parse($data['from'], 'Europe/Rome')->startOfDay(), Carbon::parse($data['to'], 'Europe/Rome')->endOfDay());
        }

        return ReportingPeriod::operational($mode, $data['date'] ?? null);
    }

    public function index(Request $request, RiderOperations $operations): View
    {
        $period = $this->period($request);

        return view('rider-operations.index', ['day' => $operations->day($period->start, end: $period->end), 'period' => $period]);
    }

    public function feed(Request $request, RiderOperations $operations): JsonResponse
    {
        $period = $this->period($request);
        $day = $operations->day($period->start, end: $period->end);
        unset($day['orders']);

        return response()->json($day)->header('Cache-Control', 'private, no-store');
    }

    public function zoneOrders(Request $request, RiderOperations $operations): View
    {
        $request->validate(['zone' => ['required', 'string', 'max:255']]);

        return $this->detail($request, $operations);
    }

    public function detail(Request $request, RiderOperations $operations): View
    {
        $period = $this->period($request);
        $date = $period->start;
        $riderId = $request->filled('rider') ? User::where('slug', $request->input('rider'))->value('id') : null;
        $query = $operations->query($date, $riderId, $request->input('zone'), $period->end);
        if ($riderId) {
            $query->where('rider_id', $riderId);
        }
        if ($request->input('scope') === 'late') {
            $query = Order::whereIn('id', $operations->lateIds($period, $riderId, $request->input('zone')));
        }
        if ($riderId) {
            $query->where('rider_id', $riderId);
        }
        if ($request->boolean('unassigned')) {
            $query->whereNull('rider_id');
        }
        if ($request->input('filter') === 'open') {
            $query->where('status', '!=', OrderStatus::Delivered);
        }
        $orders = $query->withDisplayIdentity()->with(['rider', 'delayEvents', 'scheduleEvents', 'deliveryEvent', 'events' => fn ($events) => $events->orderBy('id')])->orderBy('pickup_from')->orderBy('id')->paginate(20)->withQueryString();
        $deadlines = $orders->getCollection()->mapWithKeys(fn ($order) => [$order->slug => app(DeliveryDeadline::class)->forOrder($order, $period->reference())]);

        $compact = $request->filled('rider') || $request->boolean('unassigned');
        $backParameters = $request->only(['date', 'from', 'to', 'period', 'view', 'q', 'filter', 'sort', 'selected', 'scope', 'detail_page']);
        $returnUrl = route('rider-operations.index', $backParameters);

        return view($request->routeIs('rider-operations.zone-orders') ? 'rider-operations.zone-orders' : 'rider-operations.orders', compact('orders', 'deadlines', 'period', 'compact', 'returnUrl'));
    }

    public function show(Request $request, User $rider, RiderOperations $operations): View
    {
        abort_unless(in_array($rider->role, [UserRole::Rider, UserRole::Admin], true), 404);
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'zone' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'in:all,todo,active,completed,issues,closed'],
        ]);
        $date = Carbon::parse($data['date'] ?? now('Europe/Rome')->toDateString(), 'Europe/Rome')->startOfDay();
        $period = new ReportingPeriod($date, $date->copy()->endOfDay());
        $zone = $data['zone'] ?? null;
        $state = $data['state'] ?? 'all';
        $query = $operations->dailyWorkload($date, $rider->id, $zone);
        $counts = $operations->dailyCounts($query);
        $accounting = $operations->dailyAccounting($period, $rider->id, $query, $zone);
        $orders = $operations->filterDailyState(clone $query, $state)->withDisplayIdentity()->orderBy('pickup_from')->orderBy('id')->paginate(20)->withQueryString();

        return view('rider-operations.show', compact('rider', 'period', 'zone', 'state', 'counts', 'accounting', 'orders'));
    }
}
