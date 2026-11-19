<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\AccountSessionRevocation;
use App\Support\SecurityEvent;
use App\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Elimina un account dello staff (Rider eliminato dall'Admin, oppure l'Admin che chiude il proprio).
 *
 * Le chiavi esterne di ordini, eventi, pagamenti, spese, sospesi e audit impediscono (giustamente)
 * la cancellazione fisica: l'utente resta come riferimento storico con il solo nome, mentre email,
 * telefono, password, codici, dispositivi e sessioni vengono rimossi o revocati. L'account non può
 * più accedere né essere riattivato. Un Admin chiuso perde il ruolo, così il posto di Admin si libera:
 * il successivo si nomina soltanto dal server (php artisan app:user-role), mai da una pagina pubblica.
 */
class CloseStaffAccount
{
    public function __construct(private AccountSessionRevocation $sessions, private RecordEconomicAudit $audit) {}

    public function handle(User $actor, User $account): void
    {
        DB::transaction(function () use ($actor, $account): void {
            $locked = User::query()->lockForUpdate()->findOrFail($account->id);
            $self = $actor->is($locked);
            $allowed = $locked->closed_at === null && ($self ? $locked->role === UserRole::Admin : $locked->role === UserRole::Rider && $actor->role === UserRole::Admin && $actor->is_active);
            if (! $allowed) {
                throw new AuthorizationException;
            }
            if (Order::query()->where('rider_id', $locked->id)->whereNotIn('status', OrderStatus::closed())->exists()) {
                throw ValidationException::withMessages(['account' => $self
                    ? 'Hai spedizioni ancora aperte assegnate a te: completale o riassegnale prima di eliminare l’account.'
                    : 'Il rider ha spedizioni ancora aperte: completale o riassegnale prima di eliminare l’account.'])->errorBag($self ? 'accountClosure' : 'default');
            }
            $before = ['role' => $locked->role->value, 'is_active' => $locked->is_active, 'closed' => false];
            $this->sessions->revoke($locked);
            $now = now();
            DB::table('users')->where('id', $locked->id)->update([
                'role' => UserRole::Rider->value,
                'is_active' => false, 'deactivated_at' => $locked->deactivated_at ?? $now, 'closed_at' => $now,
                'email' => 'eliminato-'.$locked->slug.'@account.invalid', 'email_verified_at' => null,
                'password' => Hash::make(Str::random(64)), 'remember_token' => Str::random(60),
                'phone' => null, 'phone_verified_at' => null, 'pending_phone' => null,
                'phone_otp_hash' => null, 'phone_otp_expires_at' => null, 'phone_otp_sent_at' => null, 'phone_otp_attempts' => 0, 'phone_otp_window_at' => null, 'phone_otp_send_count' => 0,
                'email_otp_hash' => null, 'email_otp_expires_at' => null, 'email_otp_sent_at' => null, 'email_otp_window_at' => null, 'email_otp_attempts' => 0, 'email_otp_send_count' => 0,
                'portal_preferences' => null, 'notify_orders' => false, 'notify_messages' => false,
                'updated_at' => $now,
            ]);
            $this->audit->handle($actor, $locked, $self ? 'account.closed_by_owner' : 'user.closed', $before, ['role' => UserRole::Rider->value, 'is_active' => false, 'closed' => true]);
            SecurityEvent::record('account_closed', $locked->id, ['by_owner' => $self]);
        }, 3);
    }
}
