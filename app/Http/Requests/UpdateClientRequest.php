<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\SubscriptionStatus;
use App\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateClientRequest extends FormRequest
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
        $organization = Organization::query()->find($this->route('client'));
        $ownerId = $organization?->users()->where('role', UserRole::Admin->value)->oldest()->value('id');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('organizations', 'name')->ignore($this->route('client'))],
            'subdomain' => ['nullable', 'string', 'max:63', 'regex:/\A[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', Rule::notIn(['admin', 'www', 'mail', 'ftp', 'cpanel', 'webmail', 'autodiscover', 'autoconfig']), Rule::unique('organizations', 'subdomain')->ignore($this->route('client'))],
            'plan_name' => ['nullable', 'string', 'max:100'],
            'annual_renew_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'subscription_status' => ['required', Rule::enum(SubscriptionStatus::class)],
            'subscription_ends_at' => ['nullable', 'date'],
            'gold_store' => ['sometimes', 'boolean'],
            'shared_stock_sales' => ['sometimes', 'boolean'],
            'ecommerce_enabled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['nullable', 'required_without:owner_mobile', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ownerId)],
            'owner_mobile' => ['nullable', 'required_without:owner_email', 'string', 'max:30', 'regex:/\A\+?[0-9]{7,15}\z/D', Rule::unique('users', 'mobile')->ignore($ownerId)],
            'owner_password' => [Rule::requiredIf(! $ownerId), 'nullable', 'string', Password::min(8)],
            'owner_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('annual_renew_fee') && is_string($this->input('plan_name'))) {
            $company = Organization::query()->find($this->route('client'));
            if ($company && $this->input('plan_name') !== $company->plan_name) {
                $this->merge(['annual_renew_fee' => Organization::ANNUAL_RENEW_FEES[$this->input('plan_name')] ?? $company->annual_renew_fee]);
            }
        }

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
