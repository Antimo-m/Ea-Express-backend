<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\RiderTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RiderTrackingController extends Controller
{
    public function show(Request $request, Order $order, RiderTracking $tracking): JsonResponse
    {
        $tracking->authorizeRead($request->user(), $order);

        return response()->json($tracking->snapshot($order));
    }

    public function index(Request $request, RiderTracking $tracking): JsonResponse
    {
        $orders = Order::visibleTo($request->user())->with('rider:id,name,role,is_active,email_verified_at')
            ->whereNotNull('gps_session_id')->whereIn('status', RiderTracking::ACTIVE_STATUSES)
            ->whereNull('carrier_handed_at')->orderBy('id')->limit(100)->get();

        return response()->json(['data' => $tracking->snapshots($orders)]);
    }

    public function store(Request $request, Order $order, RiderTracking $tracking): JsonResponse
    {
        $tracking->authorizeRider($request->user(), $order);

        return response()->json(['session' => $tracking->start($order, $request->user()), 'order_id' => $order->id]);
    }

    public function update(Request $request, Order $order, RiderTracking $tracking): JsonResponse
    {
        $tracking->authorizeRider($request->user(), $order);
        $data = $request->validate([
            'session' => ['required', 'uuid'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'between:0,500'],
            'recorded_at' => ['required', 'date', 'after_or_equal:'.now()->subMinutes(2)->toIso8601String(), 'before_or_equal:'.now()->addSeconds(10)->toIso8601String()],
        ]);
        $tracking->update($order, $request->user(), $data);

        return response()->json(['message' => 'Posizione aggiornata.']);
    }

    public function destroy(Request $request, Order $order, RiderTracking $tracking): JsonResponse
    {
        $tracking->authorizeRider($request->user(), $order);
        $data = $request->validate(['session' => ['required', 'uuid']]);
        DB::transaction(function () use ($request, $order, $tracking, $data): void {
            $actor = User::lockForUpdate()->findOrFail($request->user()->id);
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            $tracking->authorizeRider($actor, $locked);
            abort_unless($locked->gps_session_id && hash_equals($locked->gps_session_id, $data['session']), 409);
            $tracking->stop($locked);
        }, 3);

        return response()->json(['message' => 'Condivisione della posizione terminata.']);
    }

    public function points(Request $request, Order $order, RiderTracking $tracking): JsonResponse
    {
        $tracking->authorizeRead($request->user(), $order);
        abort_unless($request->user()->isStaff(), 403);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'kind' => ['required', 'in:pickup,delivery'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        DB::transaction(function () use ($request, $order, $tracking, $data): void {
            $actor = User::lockForUpdate()->findOrFail($request->user()->id);
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            $tracking->authorizeRead($actor, $locked);
            abort_unless($actor->isStaff(), 403);
            abort_unless($locked->version === (int) $data['version'], 409);
            abort_if(in_array($locked->status->value, OrderStatus::closed(), true), 409);
            $column = $data['kind'].'_point';
            $locked->{$column} = ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude']];
            $locked->version++;
            $locked->save();
            DB::afterCommit(fn () => $tracking->notify($locked->id));
        }, 3);

        return response()->json(['version' => $order->fresh()->version]);
    }
}
