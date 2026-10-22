<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Database\Connection;

class DefaultCustomer
{
    public const NAME = 'General Customer';

    public function ensure(Connection $connection, Organization $organization): void
    {
        $contacts = $connection->table('contacts')->where('organization_id', $organization->id);

        if ((clone $contacts)->where('is_default', true)->exists()) {
            return;
        }

        $existing = (clone $contacts)->where('name', self::NAME)->whereIn('type', ['customer', 'both'])->oldest('id')->first();
        if ($existing) {
            $connection->table('contacts')->where('id', $existing->id)->update(['is_default' => true]);

            return;
        }

        $connection->table('contacts')->insert([
            'organization_id' => $organization->id,
            'name' => self::NAME,
            'type' => 'customer',
            'is_default' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
