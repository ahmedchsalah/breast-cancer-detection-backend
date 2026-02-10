<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Models\Organization; // <--- Import this
use App\Models\Plan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Illuminate\Support\Facades\DB;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // 1. Validate Input
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'organization_name' => ['required', 'string', 'max:255'], // <--- Custom Field
        ])->validate();

        return DB::transaction(function () use ($input) {

            // 2. Find the "Trial" Plan (created in seeder)
            $trialPlan = Plan::where('slug', 'trial')->firstOrFail();

            // 3. Create the Organization
            $org = Organization::create([
                'name' => $input['organization_name'],
                'plan_id' => $trialPlan->id,
                'type' => 'clinic', // Default type
                'code' => strtoupper(substr(md5(microtime()), 0, 8)), // Random unique code
                'contact_email' => $input['email'],
                'subscription_status' => 'trial',
                'subscription_ends_at' => now()->addDays(30), // 30 Day Trial
            ]);

            // 4. Create the User & Link to Org
            return User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'organization_id' => $org->id, // <--- Link here!
                'is_active' => true,
            ]);
        });
    }
}
