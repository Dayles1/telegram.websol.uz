<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::create([
            'name' => "user",
        ]);

        Role::create([
            'name' => "superadmin",
        ]);
        
        User::create([
            'name' => "Super Admin",
            'role_id' => Role::where('name', 'superadmin')->first()->id,
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
        ]);
    }
}
