<?php

namespace App\Console\Commands;

use App\Models\User;
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
            $user->role = $role;
            $user->save();
            $this->info('Ruolo aggiornato: '.$role->label());

            return self::SUCCESS;
        });
    }
}
