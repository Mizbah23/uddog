<?php

namespace Tests\Feature;

use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_select_individual_reports_and_unchecked_reports_are_forbidden(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Sales->value, Permission::Products->value],
        ]);

        $this->actingAs($owner)->putJson("/api/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'password' => '',
            'role' => UserRole::Staff->value,
            'organization_id' => $owner->organization_id,
            'active' => true,
            'permissions' => [Permission::Sales->value, Permission::Products->value],
            'report_permissions' => ['sales'],
        ])->assertOk()->assertJsonPath('report_permissions.0', 'sales');

        $this->assertSame(['sales'], $staff->fresh()->report_permissions);
        $this->actingAs($staff->fresh())->getJson('/api/session')->assertOk()->assertJsonPath('permissions.reports', ['sales']);
        $this->getJson('/api/reports?report=sales')->assertOk();
        $this->getJson('/api/reports?report=stock')->assertForbidden();
        $this->getJson('/api/reports?report=profit_loss')->assertForbidden();
    }

    public function test_report_checkbox_does_not_override_missing_workspace_permission(): void
    {
        $staff = User::factory()->staff()->create([
            'permissions' => [Permission::Products->value],
            'report_permissions' => ['sales', 'stock'],
        ]);

        $this->actingAs($staff)->getJson('/api/session')->assertJsonPath('permissions.reports', ['stock']);
        $this->getJson('/api/reports?report=sales')->assertForbidden();
        $this->getJson('/api/reports?report=stock')->assertOk();
    }

    public function test_existing_users_inherit_report_access_until_owner_saves_checkboxes(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Sales->value],
            'report_permissions' => null,
        ]);

        $this->actingAs($staff)->getJson('/api/reports?report=sales')->assertOk();
        $this->getJson('/api/reports?report=profit_loss')->assertOk();

        $this->actingAs($owner)->putJson("/api/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'password' => '',
            'role' => UserRole::Staff->value,
            'organization_id' => $owner->organization_id,
            'active' => true,
            'permissions' => [Permission::Sales->value],
            'report_permissions' => [],
        ])->assertOk()->assertJsonPath('report_permissions', []);

        $this->assertSame([], $staff->fresh()->report_permissions);
        $this->actingAs($staff->fresh())->getJson('/api/reports?report=sales')->assertForbidden();
    }

    public function test_owner_cannot_change_another_company_users_report_access(): void
    {
        $owner = User::factory()->admin()->create();
        $otherStaff = User::factory()->staff()->create([
            'permissions' => [Permission::Sales->value],
            'report_permissions' => ['sales'],
        ]);

        $this->actingAs($owner)->putJson("/api/users/{$otherStaff->id}", [
            'name' => $otherStaff->name,
            'email' => $otherStaff->email,
            'password' => '',
            'role' => UserRole::Staff->value,
            'organization_id' => $owner->organization_id,
            'active' => true,
            'permissions' => [Permission::Sales->value],
            'report_permissions' => [],
        ])->assertNotFound();

        $this->assertSame(['sales'], $otherStaff->fresh()->report_permissions);
    }

    public function test_unknown_report_permission_is_rejected_without_changing_access(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Sales->value],
            'report_permissions' => ['sales'],
        ]);

        $this->actingAs($owner)->putJson("/api/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'password' => '',
            'role' => UserRole::Staff->value,
            'organization_id' => $owner->organization_id,
            'active' => true,
            'permissions' => [Permission::Sales->value],
            'report_permissions' => ['unknown_report'],
        ])->assertUnprocessable()->assertJsonValidationErrors('report_permissions.0');

        $this->assertSame(['sales'], $staff->fresh()->report_permissions);
    }
}
