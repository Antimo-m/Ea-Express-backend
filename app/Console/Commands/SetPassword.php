<?php

namespace App\Console\Commands;

use App\Actions\RecordEconomicAudit;
use App\Models\User;
use App\Support\AuthSecurityStore;
use App\Support\PasswordPolicy;
use App\Support\SecurityEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class SetPassword extends Command
{
    protected $signature = 'app:set-password {email : Account a cui impostare la nuova password}';

    protected $description = 'Imposta la password di un account dal server (unico canale per l’Admin) e revoca sessioni e dispositivi';

    public function handle(): int
    {
        $user = User::query()->where('email', AuthSecurityStore::email((string) $this->argument('email')))->first();
        if (! $user) {
            $this->error('Nessun account con questa email.');

            return self::FAILURE;
        }
        $data = ['password' => $this->secret('Nuova password (almeno '.config('auth_security.password.min').' caratteri)'), 'password_confirmation' => $this->secret('Conferma la nuova password')];
        $validator = Validator::make($data, ['password' => [...PasswordPolicy::rules(), 'confirmed']]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        if (! $this->confirm('Confermi la nuova password per '.$user->email.' ('.$user->role->value.')? Tutte le sessioni e i dispositivi verranno revocati.')) {
            $this->warn('Operazione annullata.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->password = Hash::make($data['password']);
            $locked->saveRevokingSessions();
            app(RecordEconomicAudit::class)->handle(null, $locked, 'user.password-set-cli', null, ['role' => $locked->role->value]);
        }, 3);
        SecurityEvent::record('password_set_from_console', $user->id);
        $this->info('Password aggiornata. Sessioni e dispositivi dell’account sono stati revocati.');

        return self::SUCCESS;
    }
}
