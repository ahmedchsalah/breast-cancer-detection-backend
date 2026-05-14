<?php

namespace App\Http\Requests\Api\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $admin = $this->user();
        $userToUpdate = $this->route('user'); // Gets the User model from the URL

        // Admin can do anything. Org Manager can only update their own team.
        if ($admin->hasRole('admin')) return true;

        return $admin->hasRole('org_manager') && $userToUpdate->organization_id === $admin->organization_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userToUpdate = $this->route('user');

        return [
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users')->ignore($userToUpdate->id)
            ],
            'is_active' => 'sometimes|boolean',
            'role' => 'sometimes|in:doctor,org_manager',
            'password' => 'sometimes|min:8',
        ];
    }
}
