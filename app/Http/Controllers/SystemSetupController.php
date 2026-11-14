<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\SupportImpersonation;
use App\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemSetupController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        return response()->json([...$organization->setup(), 'company' => $organization->only(['name', 'phone', 'address'])]);
    }

    public function update(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'ecommerce' => ['sometimes', 'array:name,hero,subtitle,primary_color,hero_color,accent_color,logo_url,favicon_url'],
            'ecommerce.logo_url' => ['nullable', 'url:https', 'max:2048'],
            'ecommerce.favicon_url' => ['nullable', 'url:https', 'max:2048'],
            'ecommerce.name' => ['nullable', 'string', 'max:100'],
            'ecommerce.hero' => ['nullable', 'string', 'max:200'],
            'ecommerce.subtitle' => ['nullable', 'string', 'max:600'],
            'ecommerce.primary_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'ecommerce.hero_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'ecommerce.accent_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'company' => ['sometimes', 'array:name,phone,address'],
            'company.name' => ['required_with:company', 'string', 'max:255', Rule::unique('organizations', 'name')->ignore($organization)],
            'company.phone' => ['nullable', 'string', 'max:50'],
            'company.address' => ['nullable', 'string', 'max:1000'],
            'sales' => ['required', 'array:allow_due_sales,allow_installment_sales,allow_sales_returns,allow_resales,default_payment_method'],
            'sales.allow_due_sales' => ['required', 'boolean'],
            'sales.allow_installment_sales' => ['required', 'boolean'],
            'sales.allow_sales_returns' => ['required', 'boolean'],
            'sales.allow_resales' => ['required', 'boolean'],
            'sales.default_payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
            'purchases' => ['required', 'array:allow_purchase_returns'],
            'purchases.allow_purchase_returns' => ['required', 'boolean'],
            'supplier_advances' => ['sometimes', 'array:enabled'],
            'supplier_advances.enabled' => ['sometimes', 'boolean'],
            'store' => ['required', 'array:allow_stock_adjustments,allow_stock_transfers,allow_cash_handover,allow_staff_custom_dates,default_reorder_level,units,unit_conversions'],
            'store.allow_stock_adjustments' => ['required', 'boolean'],
            'store.allow_stock_transfers' => ['required', 'boolean'],
            'store.allow_cash_handover' => ['sometimes', 'boolean'],
            'store.allow_staff_custom_dates' => ['sometimes', 'boolean'],
            'store.default_reorder_level' => ['required', 'numeric', 'min:0', 'max:999999', 'decimal:0,3'],
            'store.units' => ['sometimes', 'array', 'min:1', 'max:50'],
            'store.units.*' => ['required', 'string', 'max:30', 'distinct:strict'],
            'store.unit_conversions' => ['sometimes', 'array'],
            'store.unit_conversions.*' => ['required', 'integer', 'min:1', 'max:1000000'],
            'store.unit_conversions.pc' => ['sometimes', 'integer', Rule::in([1])],
        ]);
        $data['store']['units'] = array_values(array_unique($data['store']['units'] ?? $organization->setup()['store']['units']));
        $data['store']['allow_cash_handover'] = (bool) ($data['store']['allow_cash_handover'] ?? $organization->setup()['store']['allow_cash_handover']);
        $data['store']['allow_staff_custom_dates'] = (bool) ($data['store']['allow_staff_custom_dates'] ?? $organization->setup()['store']['allow_staff_custom_dates']);
        $data['supplier_advances']['enabled'] = (bool) ($data['supplier_advances']['enabled'] ?? $organization->setup()['supplier_advances']['enabled']);
        $existingConversions = $organization->setup()['store']['unit_conversions'];
        $submittedConversions = $data['store']['unit_conversions'] ?? $existingConversions;
        $data['store']['unit_conversions'] = [];
        foreach (array_unique([...array_keys(Organization::defaultUnitConversions()), ...$data['store']['units']]) as $unit) {
            $data['store']['unit_conversions'][$unit] = $unit === 'pc' ? 1 : (int) ($submittedConversions[$unit] ?? $existingConversions[$unit] ?? 1);
        }
        $organization->update([
            ...($data['company'] ?? []),
            'system_settings' => [...collect($data)->only(['sales', 'purchases', 'supplier_advances', 'store'])->all(), 'ecommerce' => array_replace($organization->setup()['ecommerce'], $data['ecommerce'] ?? [])],
        ]);
        $provisioner->syncOrganizationShadow($organization);

        return response()->json([...$organization->fresh()->setup(), 'company' => $organization->only(['name', 'phone', 'address'])]);
    }

    private function organization(Request $request): Organization
    {
        $organization = Organization::query()->findOrFail($request->user()->organization_id);
        abort_if($organization->isDemo() && ! SupportImpersonation::activeFor($request), 403);

        return $organization;
    }
}
