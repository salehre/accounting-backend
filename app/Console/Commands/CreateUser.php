<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateUser extends Command
{
    protected $signature = 'user:create {role=admin : admin, accountant or viewer}';

    protected $description = 'Create a user, or reset the password of an existing one';

    public function handle(): int
    {
        $role = Role::query()->where('name', $this->argument('role'))->first();
        if (! $role) {
            $this->error('Role does not exist (use admin, accountant or viewer).');

            return self::FAILURE;
        }

        $username = mb_strtolower(trim((string) $this->ask('Username')));
        $fullName = trim((string) $this->ask('Full name'));
        $password = (string) $this->secret('Password (minimum 10 characters)');
        $confirm = (string) $this->secret('Repeat password');

        if ($username === '' || $fullName === '') {
            $this->error('Username and full name are required.');

            return self::FAILURE;
        }
        if (mb_strlen($password) < 10) {
            $this->error('Password must be at least 10 characters.');

            return self::FAILURE;
        }
        if (strlen($password) > 72) {
            $this->error('Password is too long (maximum 72 bytes).');

            return self::FAILURE;
        }
        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('lower(username) = ?', [$username])->first();

        if ($user) {
            $user->forceFill([
                'password_hash' => Hash::make($password),
                'is_active' => true,
            ])->save();
            $this->info("User \"{$username}\" already existed: password reset. Run 'php artisan cache:clear' to clear any login lock.");

            return self::SUCCESS;
        }

        User::forceCreate([
            'username' => $username,
            'full_name' => $fullName,
            'password_hash' => Hash::make($password),
            'role_id' => $role->id,
        ]);
        $this->info("User \"{$username}\" created with role \"{$role->name}\".");

        return self::SUCCESS;
    }
}
