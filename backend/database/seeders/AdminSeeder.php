<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@trading.local'],
            [
                'name'     => 'Admin',
                'password' => 'changeme123',
                'role'     => 'admin',
            ]
        );
    }
}
