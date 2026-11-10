<?php

namespace App\Console\Commands;

use App\Actions\RecordEconomicAudit;
use App\Models\User;
use App\Support\AccountSessionRevocation;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class TransferAdmin extends Command
{
    protected $signature = 'app:transfer-admin {email : Account attivo che riceverà l’amministrazione} {--operator= : Email dell’Admin attuale} {--reconcile : Riconcilia esplicitamente più Admin preesistenti}';

    protected $description = 'Trasferisce o riconcilia l’Admin con verifica della password, transazione e audit';

    public function handle(): int
    {
        if (! Schema::hasTable('trusted_rider_devices') || ! Schema::hasColumn('users', 'auth_version')) {
            $this->error('Prerequisiti mancanti: applica prima le migrazioni dei dispositivi attendibili e della versione delle credenziali. Nessun account è stato modificato.');
            $this->line('php artisan migrate --path=database/migrations/2026_11_10_123556_create_trusted_rider_devices_table.php --path=database/migrations/2026_11_10_123557_add_auth_version_to_users_table.php --no-interaction');

            return self::FAILURE;
        }

        $password = $this->secret('Password dell’Admin operatore');
        if (! is_string($password) || $password === '') {
            $this->error('È richiesta la password dell’Admin operatore.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($password): int {
            $users = User::query()->orderBy('id')->lockForUpdate()->get();
            $operator = $users->firstWhere('email', $this->option('operator'));
            $target = $users->firstWhere('email', $this->argument('email'));
            $admins = $users->where('role', UserRole::Admin);
            if (! $operator || $operator->role !== UserRole::Admin || ! $operator->is_active || ! Hash::check($password, $operator->password)) {
                $this->error('Operatore o password non validi.');

                return self::FAILURE;
            }
            if (! $target || ! $target->is_active || ! $target->email_verified_at) {
                $this->error('Il destinatario deve essere attivo e avere l’email verificata.');

                return self::FAILURE;
            }
            if ($admins->count() > 1 && ! $this->option('reconcile')) {
                $this->error('Più Admin presenti: verifica gli account e usa esplicitamente --reconcile.');

                return self::FAILURE;
            }
            if (! $this->confirm('Trasferire l’amministrazione a '.$target->email.' revocando le sessioni coinvolte?')) {
                return self::FAILURE;
            }
            $affected = $admins->push($target)->unique('id');
            foreach ($affected as $user) {
                $before = $user->only(['role']);
                $role = $user->id === $target->id ? UserRole::Admin : UserRole::Rider;
                app(AccountSessionRevocation::class)->revoke($user);
                // Demote all previous administrators before promoting the recipient.
                DB::table('users')->where('id', $user->id)->update([
                    'role' => UserRole::Rider->value,
                    'email_otp_hash' => null, 'email_otp_expires_at' => null, 'updated_at' => now(),
                ]);

                app(RecordEconomicAudit::class)->handle($operator, $user, 'admin.transferred', $before, ['role' => $role->value, 'admin_slug' => $target->slug, 'reconciled' => (bool) $this->option('reconcile')]);
            }
            DB::table('users')->where('id', $target->id)->update(['role' => UserRole::Admin->value]);
            $this->info('Amministrazione trasferita. È richiesto un nuovo accesso con OTP.');

            return self::SUCCESS;
        }, 3);
    }
}
