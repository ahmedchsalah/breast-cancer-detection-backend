<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
class UpdateProfileRequest extends FormRequest
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
        // We get the user ID from the currently authenticated user
        $userId = $this->user()->id;

        return [
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                // Ensure email is unique, but ignore the current user's email
                Rule::unique('users', 'email')->ignore($userId),
            ],
            // If they provide a password, it must be at least 8 chars
            'password' => 'sometimes|string|min:8',
        ];
    }
    // Automatically hash the password if it's included in the request
    protected function passedValidation()
    {
        if ($this->has('password')) {
            $this->merge([
                'password' => Hash::make($this->password)
            ]);
        }
    }
}
