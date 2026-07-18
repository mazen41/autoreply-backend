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
        $admin = User::updateOrCreate(
            ['email' => 'admin@naz.com'],
            [
                'name' => 'Admin',
                'email' => 'admin@naz.com',
                'password' => Hash::make('admin123'), // Change this in production!
                'is_admin' => true,
                'onboarding_completed' => true,
            ]
        );

        $this->command->info('Admin user created/updated: admin@naz.com (password: admin123)');
        $this->command->warn('IMPORTANT: Change the default admin password in production!');
    }
}
