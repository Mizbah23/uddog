<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\TenantContext;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemUpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    private ?string $migrationDirectory = null;

    private ?string $tenantFile = null;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        config(['tenancy.enabled' => false]);
        if ($this->tenantFile !== null) {
            unlink($this->tenantFile);
        }
        if ($this->migrationDirectory !== null) {
            unlink($this->migrationDirectory.'/2099_01_01_000000_create_system_update_probe_table.php');
            rmdir($this->migrationDirectory);
        }

        parent::tearDown();
    }

    public function test_only_superadmin_can_check_and_run_system_updates(): void
    {
        $this->registerPendingMigration();
        $owner = User::factory()->admin()->create();

        $this->getJson('/api/system-updates')->assertUnauthorized();
        $this->postJson('/api/system-updates', ['confirmed' => true])->assertUnauthorized();
        $this->actingAs($owner)->getJson('/api/system-updates')->assertForbidden();
        $this->postJson('/api/system-updates', ['confirmed' => true])->assertForbidden();

        $this->assertFalse(Schema::hasTable('system_update_probe'));
    }

    public function test_confirmed_update_runs_pending_migrations_and_clears_the_prompt(): void
    {
        $this->registerPendingMigration();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->getJson('/api/system-updates')
            ->assertOk()->assertJsonPath('central', 1)->assertJsonPath('total', 1);
        $this->postJson('/api/system-updates', ['confirmed' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertFalse(Schema::hasTable('system_update_probe'));

        $this->postJson('/api/system-updates', ['confirmed' => true])
            ->assertOk()->assertJsonPath('central', 0)->assertJsonPath('total', 0);
        $this->assertTrue(Schema::hasTable('system_update_probe'));
        $this->assertDatabaseHas('migrations', ['migration' => '2099_01_01_000000_create_system_update_probe_table']);
    }

    public function test_confirmed_update_migrates_central_and_company_databases(): void
    {
        $company = Organization::factory()->create();
        $superadmin = User::factory()->superadmin()->create();
        $database = 'uddog_update_'.Str::random(16);
        $this->tenantFile = sys_get_temp_dir().'/'.$database.'.sqlite';
        touch($this->tenantFile);
        $company->forceFill(['tenant_database' => $database])->save();
        config([
            'tenancy.enabled' => true,
            'tenancy.central_connection' => 'sqlite',
            'tenancy.base_domain' => 'test',
            'tenancy.central_host' => 'test',
            'tenancy.sqlite_directory' => sys_get_temp_dir(),
        ]);
        app(TenantContext::class)->run($company, function (): void {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--force' => true, '--no-interaction' => true]));
        });
        $this->registerPendingMigration();
        config(['tenancy.enabled' => true]);

        $this->actingAs($superadmin)->getJson('http://test/api/system-updates')
            ->assertOk()->assertJsonPath('central', 1)->assertJsonPath('tenants', 1)->assertJsonPath('companies', 1);
        $this->postJson('http://test/api/system-updates', ['confirmed' => true])
            ->assertOk()->assertJsonPath('central', 0)->assertJsonPath('tenants', 0)->assertJsonPath('total', 0);

        $this->assertTrue(Schema::hasTable('system_update_probe'));
        $this->assertTrue(app(TenantContext::class)->run($company, fn (): bool => Schema::connection('tenant')->hasTable('system_update_probe')));
    }

    private function registerPendingMigration(): void
    {
        config(['tenancy.enabled' => false, 'tenancy.central_connection' => 'sqlite']);
        $this->migrationDirectory = sys_get_temp_dir().'/uddog-update-test-'.Str::random(16);
        mkdir($this->migrationDirectory);
        file_put_contents($this->migrationDirectory.'/2099_01_01_000000_create_system_update_probe_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_update_probe', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_update_probe');
    }
};
PHP);
        app(Migrator::class)->path($this->migrationDirectory);
    }
}
