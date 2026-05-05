<?php

namespace App\Http\Requests\Api\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $org = $this->route('organization') ?? $user->organization;

        // Admin can update any. Manager can only update their own.
        if ($user->hasRole('admin')) return true;

        return $user->hasRole('org_manager') && $user->organization_id === $org->id;
    }

    public function rules(): array
    {
        $user = $this->user();

        $rules = [
            'name'          => 'sometimes|string|max:255',
            'type'          => 'sometimes|string|max:100',
            'address'       => 'nullable|string|max:500',
            'contact_email' => 'sometimes|email|max:255',
        ];

        // Only allow Admin to change the plan or the status
        if ($user->hasRole('admin')) {
            $rules['plan_id'] = 'sometimes|exists:plans,id';
            $rules['subscription_status'] = 'sometimes|in:trial,active,past_due,canceled';
        }

        return $rules;
    }
}
