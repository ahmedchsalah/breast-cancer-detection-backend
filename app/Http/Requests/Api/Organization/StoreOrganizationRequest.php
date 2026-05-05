<?php

namespace App\Http\Requests\Api\Organization;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:255',
            'plan_id'       => 'required|exists:plans,id',
            'type'          => 'required|string|max:100',
            'contact_email' => 'required|email|max:255',
            'address'       => 'nullable|string|max:500',
        ];
    }
}
