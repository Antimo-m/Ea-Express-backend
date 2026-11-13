<?php

namespace App\Http\Controllers;

use App\Actions\RecordEconomicAudit;
use App\Http\Requests\StoreRiderRequest;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\RiderActivation;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()->where('role', UserRole::Rider)
            ->addSelect([
                'assigned_count' => Order::selectRaw('count(*)')->whereColumn('rider_id', 'users.id'),
                'active_orders_count' => Order::selectRaw('count(*)')->whereColumn('rider_id', 'users.id')->whereNotIn('status', OrderStatus::closed()),
                'delivered_count' => Order::selectRaw('count(*)')->whereColumn('rider_id', 'users.id')->where('status', OrderStatus::Delivered),
            ])->orderBy('name')->orderBy('id')->paginate(20);
        $destinations = Order::query()->whereIn('rider_id', $users->modelKeys())->whereNotIn('status', OrderStatus::closed())
            ->select(['rider_id', 'delivery_city'])->selectRaw('count(*) as total')->groupBy('rider_id', 'delivery_city')->orderByDesc('total')->orderBy('delivery_city')->get()->groupBy('rider_id');

        return view('settings.users', compact('users', 'destinations'));
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('settings.create-user');
    }

    public function store(StoreRiderRequest $request): RedirectResponse
    {
        $user = new User([...$request->safe()->only(['name', 'email']), 'password' => Hash::make(Str::random(64))]);
        $user->role = UserRole::Rider;
        $user->is_active = true;
        DB::transaction(function () use ($user, $request): void {
            $user->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $user, 'rider.created', null, $user->only(['name', 'email', 'role']));
            app(RiderActivation::class)->send($user);
        });

        return redirect()->route('users.index')->with('status', 'Account rider creato. Abbiamo inviato al rider un’email per creare la password: il link vale 48 ore.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        $data = $request->validate(['action' => ['required', Rule::in(['activate', 'deactivate', 'reset-email', 'revoke-devices', 'send-activation'])]]);
        if ($data['action'] === 'send-activation') {
            abort_unless($user->role === UserRole::Rider && $user->is_active && $user->email_verified_at === null, 422, 'Il link di attivazione si invia solo ai rider attivi non ancora verificati.');
            DB::transaction(function () use ($user, $request): void {
                app(RiderActivation::class)->send($user);
                app(RecordEconomicAudit::class)->handle($request->user(), $user, 'rider.activation-sent', null, null);
            });

            return back()->with('status', 'Nuovo link di attivazione inviato al rider: vale 48 ore. Il link precedente non è più valido.');
        }
        DB::transaction(function () use ($user, $data, $request) {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            Gate::authorize('update', $target);
            if ($data['action'] === 'deactivate' && Order::where('rider_id', $target->id)->whereNotIn('status', OrderStatus::closed())->exists()) {
                throw ValidationException::withMessages(['action' => 'Il rider ha spedizioni attive: completale prima di disabilitare l’account.']);
            }
            $before = $target->only(['is_active', 'email_verified_at']);
            if ($data['action'] === 'reset-email') {
                $target->email_verified_at = null;
                $target->email_otp_hash = null;
                $target->email_otp_expires_at = null;
            } elseif ($data['action'] !== 'revoke-devices') {
                $target->is_active = $data['action'] === 'activate';
            }
            $target->saveRevokingSessions();
            app(RecordEconomicAudit::class)->handle($request->user(), $target, 'user.'.$data['action'], $before, $target->only(['is_active', 'email_verified_at']));

        }, 3);

        return back()->with('status', 'Profilo aggiornato. I dispositivi e le sessioni precedenti dell’account sono stati revocati.');
    }
}
