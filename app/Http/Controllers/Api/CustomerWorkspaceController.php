<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderResource;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Rules\SafePasswordLength;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomerWorkspaceController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $base = Order::where('customer_id', $request->user()->id);
        $active = (clone $base)->whereNotIn('status', OrderStatus::closed());

        return response()->json(['metrics' => [
            'active' => (clone $active)->count(),
            'pickups' => (clone $active)->whereDoesntHave('events', fn ($q) => $q->where('status', OrderStatus::PickedUp))->count(),
            'delivered' => (clone $base)->where('status', OrderStatus::Delivered)->count(),
            'attention' => (clone $base)->whereIn('status', ['delivery_issue', 'delivery_attempted', 'rejected'])->count(),
            'unread' => $request->user()->unreadNotifications()->count(),
        ], 'recent' => CustomerOrderResource::collection((clone $base)->with('rider')->latest()->orderByDesc('id')->limit(5)->get()), 'next_pickups' => CustomerOrderResource::collection((clone $active)->with('rider')->whereDoesntHave('events', fn ($q) => $q->where('status', OrderStatus::PickedUp))->orderBy('pickup_date')->orderBy('pickup_from')->orderBy('id')->limit(3)->get())]);
    }

    public function couriers(Request $request): JsonResponse
    {
        $orders = Order::where('customer_id', $request->user()->id)->whereNotNull('rider_id');
        $riders = User::whereIn('id', (clone $orders)->select('rider_id'))->select(['id', 'name'])->orderBy('name')->get();

        return response()->json(['data' => $riders]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $items = $request->user()->notifications()->latest()->orderByDesc('id')->paginate(20);

        return response()->json(['data' => $items->getCollection()->map(fn ($n) => ['id' => $n->id, 'title' => $n->data['title'], 'reference' => $n->data['reference'], 'order_id' => $n->data['order_id'], 'is_message' => $n->data['message'] ?? false, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at->toIso8601String()]), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'unread' => $request->user()->unreadNotifications()->count()]]);
    }

    public function readNotification(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return response()->json(['message' => 'Notifica letta.']);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifiche lette.']);
    }

    public function profile(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'lowercase', 'max:255', Rule::unique('users')->ignore($request->user()->id)]]);
        $request->user()->update($data);

        return response()->json(['message' => 'Profilo aggiornato.']);
    }

    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate(['notify_orders' => ['required', 'boolean'], 'notify_messages' => ['required', 'boolean']]);
        $user = $request->user();
        $user->notify_orders = $data['notify_orders'];
        $user->notify_messages = $data['notify_messages'];
        $user->save();

        return response()->json(['message' => 'Preferenze salvate.']);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password:customer'], 'password' => ['required', 'confirmed', Password::min(12), new SafePasswordLength]]);
        $request->user()->password = $data['password'];
        $request->user()->save();

        return response()->json(['message' => 'Password aggiornata.']);
    }
}
