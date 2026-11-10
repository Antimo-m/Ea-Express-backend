<?php

namespace App\Console\Commands;

use App\Actions\RecordEconomicAudit;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetUserRole extends Command
{
    protected $signature = 'app:user-role {email} {role : admin, rider oppure customer}';

    protected $description = 'Assegna un ruolo a un account esistente tramite accesso al server';

    public function handle(): int
    {
        $role = UserRole::tryFrom($this->argument('role'));
        if (! $role) {
            $this->error('Ruolo non valido.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($role): int {
            $users = User::query()->orderBy('id')->lockForUpdate()->get();
            $user = $users->firstWhere('email', $this->argument('email'));
            if (! $user) {
                $this->error('Account non trovato.');

                return self::FAILURE;
            }
            if ($user->role === UserRole::Admin && $role !== UserRole::Admin && $users->where('role', UserRole::Admin)->count() <= 1) {
                $this->error('Non puoi rimuovere l’ultimo amministratore.');

                return self::FAILURE;
            }
            if ($user->isStaff() && $role !== $user->role && Order::where('rider_id', $user->id)->whereNotIn('status', OrderStatus::closed())->exists()) {
                $this->error('Riassegna le spedizioni attive prima di cambiare ruolo.');

                return self::FAILURE;
            }
            if ($role === UserRole::Admin && $users->contains(fn (User $existing): bool => $existing->role === UserRole::Admin && $existing->id !== $user->id)) {
                $this->error('Esiste già un account Admin. Usa app:transfer-admin per trasferire l’amministrazione.');

                return self::FAILURE;
            }
            $before = $user->only(['role']);
            $user->role = $role;
            $user->save();
            app(RecordEconomicAudit::class)->handle(null, $user, 'user.role_changed', $before, $user->only(['role']));
            $this->info('Ruolo aggiornato: '.$role->label());

            return self::SUCCESS;
        });
    }
}
