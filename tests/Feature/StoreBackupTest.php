<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SupportImpersonation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_download_only_their_store_records(): void
    {
        $owner = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        Product::create(['organization_id' => $owner->organization_id, 'name' => "Owner's পণ্য", 'unit' => 'pc']);
        Product::create(['organization_id' => $other->organization_id, 'name' => 'Other store product', 'unit' => 'pc']);

        $response = $this->actingAs($owner)->get('/api/store-backup')->assertOk();
        $response->assertHeader('Content-Type', 'application/sql');
        $this->assertStringContainsString('attachment; filename=store-backup-', $response->headers->get('Content-Disposition'));
        $file = $response->baseResponse->getFile()->getPathname();
        try {
            $sql = file_get_contents($file);
            $this->assertStringEndsWith('.sql', $response->headers->get('Content-Disposition'));
            $this->assertSame(1, substr_count($sql, 'INSERT INTO `products`'));
            $this->assertStringContainsString("X'".bin2hex("Owner's পণ্য")."'", $sql);
            $this->assertStringNotContainsString(bin2hex('Other store product'), $sql);
            $this->assertStringNotContainsString('INSERT INTO `users`', $sql);
        } finally {
            unlink($file);
        }
    }

    public function test_staff_and_support_sessions_cannot_download_a_backup(): void
    {
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->getJson('/api/store-backup')->assertForbidden();

        $owner = User::factory()->admin()->create();
        $superadmin = User::factory()->superadmin()->create();
        $support = SupportImpersonation::create([
            'impersonator_id' => $superadmin->id,
            'impersonated_user_id' => $owner->id,
            'organization_id' => $owner->organization_id,
        ]);
        $this->actingAs($owner)->withSession([SupportImpersonation::SESSION_KEY => $support->id])
            ->getJson('/api/store-backup')->assertForbidden();
    }
}
