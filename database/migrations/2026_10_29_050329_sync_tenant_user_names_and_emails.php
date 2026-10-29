<?php

use App\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $organization = app(TenantContext::class)->organization();
        if (! config('tenancy.enabled') || ! $organization) {
            return;
        }

        $tenantUsers = DB::connection('tenant')->table('users');
        $centralUsers = DB::connection(config('tenancy.central_connection'))->table('users');
        $tenantUsers->orderBy('id')->chunkById(500, function ($rows) use ($organization, $tenantUsers, $centralUsers): void {
            $profiles = (clone $centralUsers)->whereIn('id', $rows->pluck('id'))->get(['id', 'organization_id', 'name', 'email'])->keyBy('id');

            foreach ($rows as $row) {
                $profile = $profiles->get($row->id);
                if (! $profile) {
                    continue;
                }

                $details = ['name' => $profile->name];
                if ((int) $profile->organization_id === (int) $organization->id) {
                    $details['email'] = $profile->email;
                }

                (clone $tenantUsers)->where('id', $row->id)->update($details);
            }
        });
    }

    public function down(): void
    {
        // Original placeholder values cannot be recovered after real names replace them.
    }
};
