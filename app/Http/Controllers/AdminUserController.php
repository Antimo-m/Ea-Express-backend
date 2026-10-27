<?php

namespace App\Http\Controllers;

use App\Actions\RecordEconomicAudit;
use App\Http\Requests\StoreRiderRequest;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->role = UserRole::Rider;
        $user->is_active = true;
        DB::transaction(function () use ($user, $request): void {
            $user->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $user, 'rider.created', null, $user->only(['name', 'email', 'role']));
        });

        return redirect()->route('users.index')->with('status', 'Account rider creato. Comunica le credenziali al rider attraverso un canale riservato.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        $data = $request->validate(['action' => ['required', Rule::in(['activate', 'deactivate', 'reset-email'])]]);
        DB::transaction(function () use ($user, $data) {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            Gate::authorize('update', $target);
            if ($data['action'] === 'deactivate' && Order::where('rider_id', $target->id)->whereNotIn('status', OrderStatus::closed())->exists()) {
                throw ValidationException::withMessages(['action' => 'Il rider ha spedizioni attive: completale prima di disabilitare l’account.']);
            }
            if ($data['action'] === 'reset-email') {
                $target->email_verified_at = null;
                $target->email_otp_hash = null;
                $target->email_otp_expires_at = null;
            } else {
                $target->is_active = $data['action'] === 'activate';
            }
            $target->remember_token = Str::random(60);
            $target->save();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $target->id)->delete();
            }
        }, 3);

        return back()->with('status', 'Profilo aggiornato. Le sessioni precedenti del rider sono state revocate.');
    }
}
