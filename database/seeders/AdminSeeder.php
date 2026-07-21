<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create or update the admin user
        $adminPassword = env('ADMIN_PASSWORD');
        
        if (!$adminPassword) {
            $adminPassword = str()->random(32);
            $this->command->warn('IMPORTANT: Set ADMIN_PASSWORD in .env file for production!');
            $this->command->warn("Generated random password: {$adminPassword}");
        }
        
        $admin = User::updateOrCreate(
            ['email' => 'admin@naz.com'],
            [
                'name' => 'Admin',
                'email' => 'admin@naz.com',
                'password' => $adminPassword, // Hashing is handled by User model cast
                'is_admin' => true,
                'onboarding_completed' => true,
            ]
        );

        $this->command->info('Admin user created/updated: admin@naz.com');
    }
}
