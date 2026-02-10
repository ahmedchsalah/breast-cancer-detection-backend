<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'], // requires password_confirmation field
            'role' => ['required', Rule::in(['org_manager', 'doctor'])],

            // 2. Organization Manager Fields (Required if role is org_manager)
            'organization_name' => [
                'required_if:role,org_manager',
                'string',
                'max:255'
            ],
            'plan_id' => [
                'required_if:role,org_manager',
                'exists:plans,id' // Validates that ID 1 or 2 exists in your DB
            ],
            'organization_address' => ['nullable', 'string', 'max:500'],

            // 3. Doctor Fields (Required if role is doctor)
            'organization_code' => [
                'required_if:role,doctor',
                'exists:organizations,code' // Checks if the clinic code is real
            ],
            'organization_type' => [
                'required_if:role,org_manager',
                'string',
                'in:clinic,hospital,research_lab' // <--- Restrict to valid types only
            ],
        ];
    }
}
