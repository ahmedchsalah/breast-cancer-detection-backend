<?php

namespace App\Http\Requests\Api\Plan;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
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
    protected function prepareForValidation()
    {
        // Automatically create the slug from the name if it's not provided
        if ($this->name && !$this->slug) {
            $this->merge(['slug' => Str::slug($this->name)]);
        }
    }

    public function rules(): array
    {
        return [
            'name'           => 'required|string|max:255',
            'slug'           => 'required|string|unique:plans,slug,' . $this->route('plan'),
            'price'          => 'required|numeric|min:0',
            'max_users'      => 'required|integer|min:1',
            'max_storage_gb' => 'required|integer|min:1',
        ];
    }
}
