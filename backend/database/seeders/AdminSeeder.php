<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['user_id' => 'admin'],
            [
                'name'     => 'Admin',
                'email'    => 'admin@trading.local',
                'password' => 'changeme123',
                'role'     => 'admin',
            ]
        );
    }
}
