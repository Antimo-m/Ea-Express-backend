<?php

namespace App\Http\Controllers\Api;

use App\Actions\ChangePassword;
use App\Actions\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderResource;
use App\Models\Order;
use App\OrderStatus;
use App\Rules\AllowedPortalText;
use App\Rules\SafePasswordLength;
use App\Support\CustomerIdentity;
use App\Support\NotificationInbox;
use App\Support\PortalTextPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        ], 'live_order' => ($liveOrder = (clone $active)->withDisplayIdentity()->with('rider')->whereNotNull('rider_id')->latest('updated_at')->orderByDesc('id')->first()) ? new CustomerOrderResource($liveOrder) : null, 'recent' => CustomerOrderResource::collection((clone $base)->withDisplayIdentity()->with('rider')->latest()->orderByDesc('id')->limit(5)->get()), 'next_pickups' => CustomerOrderResource::collection((clone $active)->withDisplayIdentity()->with('rider')->whereDoesntHave('events', fn ($q) => $q->where('status', OrderStatus::PickedUp))->orderBy('pickup_date')->orderBy('pickup_from')->orderBy('id')->limit(3)->get())]);
    }

    public function notifications(Request $request): JsonResponse
    {
        if ($request->boolean('grouped')) {
            $groups = app(NotificationInbox::class)->groups($request->user());

            return response()->json(['data' => $groups->items(), 'meta' => ['current_page' => $groups->currentPage(), 'last_page' => $groups->lastPage(), 'unread' => $request->user()->unreadNotifications()->count()]]);
        }
        $items = $request->user()->notifications()->latest()->orderByDesc('id')->paginate(20);

        $slugs = Order::whereIn('id', $items->getCollection()->map(fn ($item) => $item->data['order_id']))->pluck('slug', 'id')->all();

        return response()->json(['data' => $items->getCollection()->map(fn ($item) => app(NotificationInbox::class)->item($item, $slugs)), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'unread' => $request->user()->unreadNotifications()->count()]]);
    }

    public function feed(Request $request, NotificationInbox $inbox): JsonResponse
    {
        return response()->json($inbox->feed($request->user()));
    }

    public function history(Request $request, Order $order, NotificationInbox $inbox): JsonResponse
    {
        return response()->json($inbox->history($request->user(), $order->id));
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

    public function profile(Request $request, UpdateProfile $update): JsonResponse
    {
        $request->merge(PortalTextPolicy::normalizeFields($request->only(['name', 'business_type', 'business_description'])));
        $rules = CustomerIdentity::rules();
        $rules['business_type'][] = new AllowedPortalText;
        $rules['business_description'][] = new AllowedPortalText;
        $data = $request->validate([...$rules, 'current_password' => [Rule::excludeIf($request->input('email') === $request->user()->email), 'bail', 'required', new SafePasswordLength, 'current_password:customer'], 'name' => ['required', 'string', 'max:150', Rule::when(($request->input('sender_type') ?? $request->user()->sender_type) !== 'private', [new AllowedPortalText])], 'email' => ['required', 'email', 'lowercase', 'max:255', Rule::unique('users')->ignore($request->user()->id)]]);
        $profile = CustomerIdentity::normalize(Arr::except($data, ['current_password']), $request->user()->sender_type);
        if ($update->handle($request->user(), $profile, $data['current_password'] ?? null)) {
            $request->session()->regenerate();
        }

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
        $data = $request->validate(['current_password' => ['bail', 'required', 'string', 'max:72', 'current_password:customer'], 'password' => ['required', 'confirmed', Password::min(12), new SafePasswordLength]]);
        app(ChangePassword::class)->handle($request->user(), $data['current_password'], $data['password']);
        $request->session()->put('password_hash_customer', $request->user()->password);
        $request->session()->regenerate();

        return response()->json(['message' => 'Password aggiornata.']);
    }
}
