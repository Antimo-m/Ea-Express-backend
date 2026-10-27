<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\Support\DeliveryDeadline;
use App\Support\ReportingPeriod;
use App\Support\RiderOperations;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RiderOperationsController extends Controller
{
    private function period(Request $request): ReportingPeriod
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'period' => ['nullable', 'in:today,week,month,custom'], 'view' => ['nullable', 'in:zone,rider'], 'zone' => ['nullable', 'string', 'max:255'], 'rider' => ['nullable', 'uuid', 'exists:users,slug'], 'scope' => ['nullable', 'in:programmed,late'], 'q' => ['nullable', 'string', 'max:255'], 'filter' => ['nullable', 'in:all,open,late'], 'sort' => ['nullable', 'in:name,late,volume']]);

        return ReportingPeriod::operational($data['period'] ?? (isset($data['date']) ? 'custom' : 'today'), $data['date'] ?? null);
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
        $backParameters = $request->only(['date', 'period', 'view', 'q', 'filter', 'sort', 'selected', 'scope', 'detail_page']);
        $returnUrl = route('rider-operations.index', $backParameters);

        return view($request->routeIs('rider-operations.zone-orders') ? 'rider-operations.zone-orders' : 'rider-operations.orders', compact('orders', 'deadlines', 'period', 'compact', 'returnUrl'));
    }

    public function show(Request $request, User $rider, RiderOperations $operations): View
    {
        abort_unless(in_array($rider->role, [UserRole::Rider, UserRole::Admin], true), 404);
        $period = $this->period($request);
        $date = $period->start;
        $day = $operations->day($date, $rider->id, $period->end, $request->input('zone'));
        $zone = $request->input('zone');
        $query = $operations->query($date, $rider->id, $zone, $period->end);
        $programmedCount = (clone $query)->count();
        $listQuery = $request->input('scope') === 'late' ? Order::whereIn('id', $operations->lateIds($period, $rider->id, $zone)) : clone $query;
        $orders = $listQuery->withDisplayIdentity()->with(['rider', 'delayEvents', 'scheduleEvents', 'events' => fn ($events) => $events->with('user')->orderBy('id'), 'payments.user'])->orderBy('pickup_from')->orderBy('id')->paginate(10)->withQueryString();
        $activity = $operations->activity($orders->getCollection(), $rider->id);
        $deliveries = $activity->filter(fn ($item) => $item['event']->status === OrderStatus::Delivered && $item['event']->delay_action === null)->values();
        $deliveredQuery = (clone $query)->where('status', OrderStatus::Delivered)->where('rider_id', $rider->id);
        $deliveredCount = (clone $deliveredQuery)->count();
        $payments = PaymentEntry::whereIn('order_id', (clone $deliveredQuery)->select('id'));
        $cash = (int) (clone $payments)->sum('amount_cents');
        $retained = (int) (clone $payments)->sum(DB::raw('COALESCE(ea_amount_cents, amount_cents)'));
        $unallocated = (clone $payments)->whereNull('ea_amount_cents')->whereHas('order', fn ($query) => $query->where('shipping_type', 'external'))->count();
        $tariff = (int) (clone $deliveredQuery)->sum('price_cents');
        $remaining = (clone $query)->where('status', '!=', OrderStatus::Delivered)->count();
        $deadlines = $orders->getCollection()->mapWithKeys(fn ($order) => [$order->slug => app(DeliveryDeadline::class)->forOrder($order, $period->reference())]);

        return view('rider-operations.show', compact('rider', 'day', 'zone', 'period', 'activity', 'deliveries', 'deliveredCount', 'programmedCount', 'orders', 'cash', 'retained', 'tariff', 'unallocated', 'remaining', 'deadlines'));
    }
}
