<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Plan;
class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::create([
            'name' => 'Free Trial',
            'slug' => 'trial',
            'price' => 0.00,
            'max_users' => 1,
            'max_storage_gb' => 1,
        ]);

        // 2. Pro Plan
        Plan::create([
            'name' => 'Pro Clinic',
            'slug' => 'pro',
            'price' => 5000.00,
            'max_users' => 5,
            'max_storage_gb' => 50,
        ]);

    }
}
