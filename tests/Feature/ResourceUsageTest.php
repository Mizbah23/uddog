<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ResourceUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmin_can_read_cached_hosting_account_samples(): void
    {
        config(['resource_usage.cpanel_account' => 'storehost']);
        Process::fake(['*cpapi2*' => Process::result(output: json_encode(['cpanelresult' => [
            'event' => ['result' => 1],
            'data' => [
                ['ID' => 'anotherhost', 'mCPU' => '99'],
                ['ID' => 'storehost', 'From' => '11-25 09:00', 'mCPU' => '28', 'lCPU' => '100'],
            ],
        ]]))]);

        $this->actingAs(User::factory()->admin()->create())->getJson('/api/resource-usage')->assertForbidden();
        $this->actingAs(User::factory()->superadmin()->create())->getJson('/api/resource-usage')
            ->assertOk()->assertJsonPath('status', 'ready')->assertJsonCount(1, 'samples')
            ->assertJsonPath('samples.0.mCPU', '28')->assertJsonMissingPath('samples.0.ID');
        $this->getJson('/api/resource-usage')->assertOk()->assertJsonPath('status', 'ready');
        Process::assertRanTimes(['/usr/local/cpanel/bin/cpapi2', '--user=storehost', '--output=json', 'LVEInfo', 'getUsage']);
    }
}
