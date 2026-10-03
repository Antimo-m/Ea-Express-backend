<?php

namespace App\Http\Controllers;

use App\Models\RiderGpsSample;
use App\Models\User;
use App\OrderStatus;
use App\Support\ReportingPeriod;
use App\Support\RiderOperations;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class RiderOperationsController extends Controller
{
    private function date(Request $request): Carbon
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Rome')->toDateString()], 'zone' => ['nullable', 'string', 'max:255']]);

        return Carbon::parse($data['date'] ?? now('Europe/Rome')->toDateString(), 'Europe/Rome')->startOfDay();
    }

    public function index(Request $request, RiderOperations $operations): View
    {
        return view('rider-operations.index', ['day' => $operations->day($this->date($request))]);
    }

    public function feed(Request $request, RiderOperations $operations): JsonResponse
    {
        $day = $operations->day($this->date($request));
        unset($day['activity'], $day['deliveries'], $day['orders']);

        return response()->json($day)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, User $rider, RiderOperations $operations): View
    {
        abort_unless(in_array($rider->role, [UserRole::Rider, UserRole::Admin], true), 404);
        $day = $operations->day($this->date($request), true, $rider->id);
        $zone = $request->input('zone');
        $matches = fn (array $item): bool => $item['rider_id'] === $rider->id && (! $zone || $item['zone'] === $zone);
        $activity = $day['activity']->filter($matches)->sortBy(fn ($item) => $item['event']->created_at->format('Y-m-d H:i:s').sprintf('%012d', $item['event']->id))->values();
        $deliveries = $day['deliveries']->filter($matches)->values();
        $orders = $activity->merge($deliveries)->pluck('order')->unique('id')->values();
        if ($day['today']) {
            $orders = $orders->merge($day['orders']->filter(fn ($order) => $order->rider_id === $rider->id && ! $order->carrier_handed_at && ! in_array($order->status->value, [...OrderStatus::closed(), 'received'], true) && (! $zone || $operations->zone($order) === $zone)))->unique('id')->values();
        }
        $deliveredOrders = $deliveries->pluck('order')->unique('id');
        $payments = $deliveredOrders->flatMap(fn ($order) => $order->payments);
        $cash = (int) $payments->sum('amount_cents');
        $retained = (int) $payments->sum(fn ($payment) => $payment->ea_amount_cents ?? $payment->amount_cents);
        $unallocated = $deliveredOrders->where('shipping_type', 'external')->sum(fn ($order) => $order->payments->whereNull('ea_amount_cents')->count());
        $tariff = (int) $deliveredOrders->sum('price_cents');
        $zones = $deliveries->pluck('zone')->unique()->values();

        $remaining = $day['today'] ? $orders->filter(fn ($order) => ! in_array($order->status->value, OrderStatus::closed(), true))->count() : $activity->groupBy(fn ($item) => $item['order']->id)->filter(fn ($items) => ! in_array($items->last()['event']->status->value, OrderStatus::closed(), true))->count();
        $gpsPeriod = new ReportingPeriod($this->date($request), $this->date($request)->endOfDay());
        $samples = RiderGpsSample::where('rider_id', $rider->id)->whereBetween('recorded_at', $gpsPeriod->utcRange())->where('captured_at', '>=', now()->subDays(config('tracking.history_days')))->when($zone, fn ($query) => $query->whereIn('order_id', $orders->pluck('id')->all()))->orderBy('recorded_at')->limit(1440)->get();
        $gpsPoints = $samples->map(fn ($sample) => ['kind' => 'sample', 'rider_id' => $rider->id, 'rider_name' => $rider->name, 'reference' => 'Campione GPS', 'order_url' => route('orders.show', $sample->order_id), 'location' => [...$sample->position, 'recorded_at' => $sample->recorded_at->toIso8601String(), 'received_at' => $sample->captured_at->toIso8601String()]])->values()->all();
        if ($gpsPoints) {
            $gpsPoints[array_key_last($gpsPoints)]['kind'] = 'last-sample';
        }

        return view('rider-operations.show', compact('rider', 'day', 'zone', 'activity', 'deliveries', 'orders', 'cash', 'retained', 'tariff', 'zones', 'unallocated', 'gpsPoints', 'remaining'));
    }
}
