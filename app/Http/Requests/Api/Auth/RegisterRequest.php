<?php

namespace App\Http\Requests\Api\Auth;

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
            // 1. Basic User Fields
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required_without:invitation_token', 'nullable', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'], // requires password_confirmation field
            'phone_number' => ['required', 'string', 'max:20', 'unique:users,phone_number'],
            'role' => ['required', Rule::in(['org_manager', 'doctor', 'instructor'])],

            // Invitation token (optional — when present, overrides email/role/org)
            'invitation_token' => ['nullable', 'string', 'exists:invitations,token'],

            // 2. Organization Manager Fields (Required if role is org_manager)
            'organization_name' => [
                'required_if:role,org_manager',
                'string',
                'max:255'
            ],
            'plan_id' => [
                'nullable', // أصبحت اختيارية ليتم تحديدها لاحقاً بعد دفع الاشتراك
                'exists:plans,id'
            ],
            'organization_address' => ['nullable', 'string', 'max:500'],
            'organization_type' => [
                'required_if:role,org_manager',
                'string',
                'in:clinic,hospital,laboratory,radiology_center' // <--- تم التحديث لتتطابق مع الـ Enum تماماً
            ],

            // 3. Doctor Fields (Required if role is doctor and no invitation token)
            'organization_id' => [ // <--- تم تغييرها من code إلى id بناءً على استخدام القائمة المنسدلة
                'required_if:role,doctor',
                'nullable',
                'exists:organizations,id' // التحقق من أن المنظمة موجودة فعلاً في قاعدة البيانات
            ],
        ];
    }
}
