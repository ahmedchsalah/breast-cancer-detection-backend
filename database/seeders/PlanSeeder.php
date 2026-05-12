<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Plan;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::truncate();

        // ── Starter ──────────────────────────────────────────────
        Plan::create([
            'name'                      => 'Starter',
            'slug'                      => 'starter',
            'description'               => 'Ideal for small clinics getting started. Limited predictions, no FL contribution.',
            'price'                     => 12000.00,
            'max_doctors'               => 2,
            'max_predictions_per_month' => 5,
            'fl_contribution_allowed'   => false,
            'instructor_allowed'        => false,
            'is_active'                 => true,
        ]);

        // ── Pro ───────────────────────────────────────────────────
        Plan::create([
            'name'                      => 'Pro',
            'slug'                      => 'pro',
            'description'               => 'For growing organizations. More doctors, more predictions, limited FL participation.',
            'price'                     => 25000.00,
            'max_doctors'               => 5,
            'max_predictions_per_month' => 15,
            'fl_contribution_allowed'   => true,   // limited contribution
            'instructor_allowed'        => false,
            'is_active'                 => true,
        ]);

        // ── Gold ──────────────────────────────────────────────────
        Plan::create([
            'name'                      => 'Gold',
            'slug'                      => 'gold',
            'description'               => 'Unlimited access. Full FL contribution and instructor invitations.',
            'price'                     => 50000.00,
            'max_doctors'               => -1,     // unlimited
            'max_predictions_per_month' => -1,     // unlimited
            'fl_contribution_allowed'   => true,
            'instructor_allowed'        => true,
            'is_active'                 => true,
        ]);
    }
}
