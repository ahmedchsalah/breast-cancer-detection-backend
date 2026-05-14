<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Ensure the 'admin' role exists (Spatie Permission)
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // 2. Create the Admin User
        // You can change these credentials before running the seeder
        $email = 'bahi72430@gmail.com'; 
        $password = 'QSDFQSDF';

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'System Admin',
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 3. Assign the role
        $admin->assignRole($adminRole);

        $this->command->info("Admin user '$email' created/updated with password: '$password'");
    }
}
