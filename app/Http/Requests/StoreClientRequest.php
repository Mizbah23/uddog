<?php

namespace App\Http\Requests;

use App\SubscriptionStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:organizations,name'],
            'subdomain' => ['nullable', 'string', 'max:63', 'regex:/\A[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', Rule::notIn(['admin', 'www', 'mail', 'ftp', 'cpanel', 'webmail', 'autodiscover', 'autoconfig']), Rule::unique('organizations', 'subdomain')],
            'plan_name' => ['nullable', 'string', 'max:100'],
            'subscription_status' => ['required', Rule::enum(SubscriptionStatus::class)],
            'subscription_ends_at' => ['nullable', 'date'],
            'gold_store' => ['sometimes', 'boolean'],
            'shared_stock_sales' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['nullable', 'required_without:owner_mobile', 'email', 'max:255', 'unique:users,email'],
            'owner_mobile' => ['nullable', 'required_without:owner_email', 'string', 'max:30', 'regex:/\A\+?[0-9]{7,15}\z/D', 'unique:users,mobile'],
            'owner_password' => ['required', 'string', Password::min(8)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('subdomain'))) {
            $this->merge(['subdomain' => strtolower(trim($this->input('subdomain'))) ?: null]);
        }
        foreach (['owner_email', 'owner_mobile'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) ?: null]);
            }
        }
    }
}
