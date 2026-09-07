<?php

namespace App\Console\Commands;

use App\Models\User;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateRider extends Command
{
    protected $signature = 'app:create-rider {email} {name}';

    protected $description = 'Crea un rider; la password viene richiesta senza mostrarla e l’email resta da verificare';

    public function handle(): int
    {
        $data = ['email' => $this->argument('email'), 'name' => $this->argument('name'), 'password' => $this->secret('Password iniziale (almeno 12 caratteri)')];
        $validator = Validator::make($data, ['email' => ['required', 'email', 'lowercase', 'unique:users,email'], 'name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:12', 'max:72']]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User($data);
        $user->role = UserRole::Rider;
        $user->save();
        $this->info('Rider creato. Al primo accesso dovrà richiedere e seguire il link di verifica email.');

        return self::SUCCESS;
    }
}
