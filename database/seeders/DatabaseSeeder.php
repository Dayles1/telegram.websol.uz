<?php

namespace Database\Seeders;

use App\Enums\Role as RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Safe to run on any database: roles are created only if missing, and a first
 * superadmin only if there is none. Its password comes from SEED_ADMIN_PASSWORD
 * or is generated and printed once (the old seeder used "password").
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleName::cases() as $role) {
            Role::query()->firstOrCreate(['name' => $role->value]);
        }

        $superadmin = Role::query()->where('name', RoleName::Superadmin->value)->value('id');
        if (User::query()->where('role_id', $superadmin)->exists()) {
            return;
        }

        $password = (string) (env('SEED_ADMIN_PASSWORD') ?: Str::password(16, symbols: false));
        $email = (string) config('telegram.superadmin_email'); // the only email that is ever superadmin
        User::query()->create([
            'name' => 'Super Admin',
            'email' => $email,
            'password' => $password,
            'role_id' => $superadmin,
        ]);

        $this->command?->warn("Superadmin created: {$email} / {$password} (change it after the first login)");
    }
}
