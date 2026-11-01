<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')->whereIn('plan_name', ['Starter', 'Standard'])
            ->whereNotNull('package_limits')->orderBy('id')->chunkById(200, function ($companies): void {
                foreach ($companies as $company) {
                    if (strtolower($company->subdomain ?? '') === 'demo') {
                        continue;
                    }
                    $limits = json_decode($company->package_limits, true);
                    $oldLimit = $company->plan_name === 'Starter' ? 1000 : 5000;
                    if (! is_array($limits) || ! isset($limits['sale_invoices']) || (int) $limits['sale_invoices'] !== $oldLimit) {
                        continue;
                    }
                    $limits['sale_invoices'] = $company->plan_name === 'Starter' ? 5000 : 15000;
                    DB::table('organizations')->where('id', $company->id)->update(['package_limits' => json_encode($limits)]);
                }
            });
    }

    public function down(): void
    {
        // Preserve company allowances: later custom changes cannot be distinguished from migrated values.
    }
};
