<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\SupportImpersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemSetupController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->organization($request)->setup());
    }

    public function update(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'sales' => ['required', 'array:allow_due_sales,allow_installment_sales,allow_sales_returns,allow_resales,default_payment_method'],
            'sales.allow_due_sales' => ['required', 'boolean'],
            'sales.allow_installment_sales' => ['required', 'boolean'],
            'sales.allow_sales_returns' => ['required', 'boolean'],
            'sales.allow_resales' => ['required', 'boolean'],
            'sales.default_payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
            'purchases' => ['required', 'array:allow_purchase_returns'],
            'purchases.allow_purchase_returns' => ['required', 'boolean'],
            'store' => ['required', 'array:allow_stock_adjustments,allow_stock_transfers,default_reorder_level,units,unit_conversions'],
            'store.allow_stock_adjustments' => ['required', 'boolean'],
            'store.allow_stock_transfers' => ['required', 'boolean'],
            'store.default_reorder_level' => ['required', 'numeric', 'min:0', 'max:999999', 'decimal:0,3'],
            'store.units' => ['sometimes', 'array', 'min:1', 'max:50'],
            'store.units.*' => ['required', 'string', 'max:30', 'distinct:strict'],
            'store.unit_conversions' => ['sometimes', 'array'],
            'store.unit_conversions.*' => ['required', 'integer', 'min:1', 'max:1000000'],
            'store.unit_conversions.pc' => ['sometimes', 'integer', Rule::in([1])],
        ]);
        $data['store']['units'] = array_values(array_unique($data['store']['units'] ?? $organization->setup()['store']['units']));
        $existingConversions = $organization->setup()['store']['unit_conversions'];
        $submittedConversions = $data['store']['unit_conversions'] ?? $existingConversions;
        $data['store']['unit_conversions'] = [];
        foreach (array_unique([...array_keys(Organization::defaultUnitConversions()), ...$data['store']['units']]) as $unit) {
            $data['store']['unit_conversions'][$unit] = $unit === 'pc' ? 1 : (int) ($submittedConversions[$unit] ?? $existingConversions[$unit] ?? 1);
        }
        $organization->update(['system_settings' => $data]);

        return response()->json($organization->fresh()->setup());
    }

    private function organization(Request $request): Organization
    {
        $organization = Organization::query()->findOrFail($request->user()->organization_id);
        abort_if($organization->isDemo() && ! SupportImpersonation::activeFor($request), 403);

        return $organization;
    }
}
