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

        if ((clone $contacts)->where('name', self::NAME)->whereIn('type', ['customer', 'both'])->exists()) {
            return;
        }

        $connection->table('contacts')->insert([
            'organization_id' => $organization->id,
            'name' => self::NAME,
            'type' => 'customer',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
