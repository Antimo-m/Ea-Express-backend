<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\View\View;

class TrackingController extends Controller
{
    public function index(Request $request): View
    {
        return view('tracking.index', ['orders' => Order::visibleTo($request->user())->withDisplayIdentity()->with('rider:id,name')->whereNotNull('tracking_started_at')->latest('updated_at')->orderByDesc('id')->paginate(15)]);
    }

    public function realtime(Request $request, string $token): JsonResponse
    {
        $order = Order::where('tracking_token', $token)->whereNotNull('tracking_started_at')->firstOrFail();
        $channel = 'private-tracking.'.hash('sha256', $order->tracking_token);
        if ($request->isMethod('POST')) {
            $data = $request->validate(['socket_id' => ['required', 'regex:/^\d+\.\d+$/D'], 'channel_name' => ['required', 'string', 'max:100']]);
            abort_unless(hash_equals($channel, $data['channel_name']), 403);
            $result = Broadcast::connection('reverb')->getPusher()->authorizeChannel($channel, $data['socket_id']);

            return response()->json(json_decode($result, true));
        }

        return response()->json(['key' => config('broadcasting.connections.reverb.key'), 'host' => config('realtime.host'), 'port' => config('realtime.port'), 'scheme' => config('realtime.scheme'), 'channel' => substr($channel, 8)]);
    }

    public function show(Request $request, string $token): View
    {
        $order = Order::query()->where('tracking_token', $token)->whereNotNull('tracking_started_at')->firstOrFail();

        $returnUrl = url('/');
        $returnLabel = 'Torna a EA-Express';
        if ($request->user()?->isStaff()) {
            $returnUrl = $request->user()->can('view', $order) ? route('orders.show', $order) : route('dashboard');
            $returnLabel = 'Torna al gestionale';
        } elseif ($request->user()?->role === UserRole::Customer) {
            $returnUrl = rtrim(config('customer.frontend_url'), '/').'/shipments';
            $returnLabel = 'Torna alle spedizioni';
        }

        return view('tracking.public', ['returnUrl' => $returnUrl, 'returnLabel' => $returnLabel, 'shippingType' => $order->shipping_type, 'carrierTracking' => $order->carrier_tracking, 'carrierStatus' => $order->carrier_status, 'deliveryFrom' => $order->estimated_delivery_from, 'deliveryTo' => $order->estimated_delivery_to, 'reference' => $order->reference, 'status' => $order->status, 'estimated' => $order->estimated_at, 'events' => $order->events()->select(['id', 'status', 'public_note', 'created_at'])->latest()->orderByDesc('id')->paginate(30)]);
    }
}
