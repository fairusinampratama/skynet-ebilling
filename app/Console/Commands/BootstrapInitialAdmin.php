<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapInitialAdmin extends Command
{
    protected $signature = 'users:bootstrap-initial-admin';

    protected $description = 'Create the first production superadmin from environment variables.';

    public function handle(): int
    {
        if (User::where('role', 'superadmin')->exists()) {
            $this->info('A superadmin already exists. Skipping initial admin bootstrap.');

            return self::SUCCESS;
        }

        $payload = [
            'name' => getenv('INITIAL_ADMIN_NAME') ?: null,
            'email' => getenv('INITIAL_ADMIN_EMAIL') ?: null,
            'password' => getenv('INITIAL_ADMIN_PASSWORD') ?: null,
        ];

        $validator = Validator::make($payload, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        if ($validator->fails()) {
            $this->error('Initial admin bootstrap failed. Set valid INITIAL_ADMIN_NAME, INITIAL_ADMIN_EMAIL, and INITIAL_ADMIN_PASSWORD values.');

            foreach ($validator->errors()->all() as $error) {
                $this->line(" - {$error}");
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->name = $payload['name'];
        $user->email = $payload['email'];
        $user->password = Hash::make($payload['password']);
        $user->email_verified_at = now();
        $user->role = 'superadmin';
        $user->save();

        $this->info("Initial superadmin created for {$payload['email']}.");

        return self::SUCCESS;
    }
}
