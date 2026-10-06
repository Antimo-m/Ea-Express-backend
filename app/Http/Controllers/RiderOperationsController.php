<?php

namespace App\Http\Controllers;

use App\Models\PaymentEntry;
use App\Models\User;
use App\OrderStatus;
use App\Support\DeliveryDeadline;
use App\Support\RiderOperations;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RiderOperationsController extends Controller
{
    private function date(Request $request): Carbon
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'zone' => ['nullable', 'string', 'max:255'], 'rider' => ['nullable', 'uuid', 'exists:users,slug']]);

        return Carbon::parse($data['date'] ?? now('Europe/Rome')->toDateString(), 'Europe/Rome')->startOfDay();
    }

    public function index(Request $request, RiderOperations $operations): View
    {
        return view('rider-operations.index', ['day' => $operations->day($this->date($request))]);
    }

    public function feed(Request $request, RiderOperations $operations): JsonResponse
    {
        $day = $operations->day($this->date($request));
        unset($day['orders']);

        return response()->json($day)->header('Cache-Control', 'private, no-store');
    }

    public function detail(Request $request, RiderOperations $operations): View
    {
        $date = $this->date($request);
        $riderId = $request->filled('rider') ? User::where('slug', $request->input('rider'))->value('id') : null;
        $query = $operations->query($date, $riderId, $request->input('zone'));
        if ($request->boolean('unassigned')) {
            $query->whereNull('rider_id');
        }
        $orders = $query->withDisplayIdentity()->with(['rider', 'events' => fn ($events) => $events->orderBy('id'), 'payments.user'])->orderBy('pickup_from')->orderBy('id')->paginate(20)->withQueryString();
        $deadlines = $orders->getCollection()->mapWithKeys(fn ($order) => [$order->slug => app(DeliveryDeadline::class)->forOrder($order)]);

        return view('rider-operations.orders', compact('orders', 'deadlines'));
    }

    public function show(Request $request, User $rider, RiderOperations $operations): View
    {
        abort_unless(in_array($rider->role, [UserRole::Rider, UserRole::Admin], true), 404);
        $date = $this->date($request);
        $day = $operations->day($date, $rider->id);
        $zone = $request->input('zone');
        $query = $operations->query($date, $rider->id, $zone);
        $orders = (clone $query)->withDisplayIdentity()->with(['rider', 'events' => fn ($events) => $events->with('user')->orderBy('id'), 'payments.user'])->orderBy('pickup_from')->orderBy('id')->paginate(20)->withQueryString();
        $activity = $operations->activity($orders->getCollection(), $rider->id);
        $deliveries = $activity->filter(fn ($item) => $item['event']->status === OrderStatus::Delivered)->values();
        $deliveredQuery = (clone $query)->where('status', OrderStatus::Delivered)->where('rider_id', $rider->id);
        $deliveredCount = (clone $deliveredQuery)->count();
        $payments = PaymentEntry::whereIn('order_id', (clone $deliveredQuery)->select('id'));
        $cash = (int) (clone $payments)->sum('amount_cents');
        $retained = (int) (clone $payments)->sum(DB::raw('COALESCE(ea_amount_cents, amount_cents)'));
        $unallocated = (clone $payments)->whereNull('ea_amount_cents')->whereHas('order', fn ($query) => $query->where('shipping_type', 'external'))->count();
        $tariff = (int) (clone $deliveredQuery)->sum('price_cents');
        $remaining = (clone $query)->where('status', '!=', OrderStatus::Delivered)->count();
        $deadlines = $orders->getCollection()->mapWithKeys(fn ($order) => [$order->slug => app(DeliveryDeadline::class)->forOrder($order)]);

        return view('rider-operations.show', compact('rider', 'day', 'zone', 'activity', 'deliveries', 'deliveredCount', 'orders', 'cash', 'retained', 'tariff', 'unallocated', 'remaining', 'deadlines'));
    }
}
