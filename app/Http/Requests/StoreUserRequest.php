<?php

namespace App\Http\Requests;

use App\Permission;
use App\ReportType;
use App\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::Superadmin, UserRole::Admin) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'required_if:role,superadmin', 'required_without:mobile', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['nullable', 'required_without:email', 'string', 'max:30', 'regex:/\A\+?[0-9]{7,15}\z/D', 'unique:users,mobile'],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'active' => ['sometimes', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(Permission::class)],
            'report_permissions' => ['nullable', 'array'],
            'report_permissions.*' => ['string', 'distinct', Rule::enum(ReportType::class)],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'mobile'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) ?: null]);
            }
        }
    }
}
