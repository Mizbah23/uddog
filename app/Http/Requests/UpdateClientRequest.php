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
            'plan_name' => ['nullable', 'string', 'max:100'],
            'subscription_status' => ['required', Rule::enum(SubscriptionStatus::class)],
            'subscription_ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ownerId)],
            'owner_password' => [Rule::requiredIf(! $ownerId), 'nullable', 'string', Password::min(8)],
            'owner_active' => ['required', 'boolean'],
        ];
    }
}
