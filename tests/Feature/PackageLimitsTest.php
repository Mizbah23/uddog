<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\EcommerceOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PackageLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $plan = 'Starter', ?array $limits = null): User
    {
        $company = Organization::factory()->create(['plan_name' => $plan, 'package_limits' => $limits]);

        return User::factory()->admin()->for($company)->create();
    }

    private function branchPayload(string $code = 'SECOND', bool $active = true): array
    {
        return ['name' => $code.' Branch', 'code' => $code, 'is_default' => false, 'active' => $active];
    }

    private function userPayload(User $owner, string $email = 'new-staff@example.test', bool $active = true): array
    {
        return ['name' => 'Staff account', 'email' => $email, 'password' => 'Password123', 'role' => 'manager', 'active' => $active,
            'permissions' => ['sales'], 'branch_ids' => [$owner->organization->branches()->where('is_default', true)->value('id')]];
    }

    private function saleRecords(User $owner): array
    {
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Quota customer', 'type' => 'both']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Quota product', 'unit' => 'pc', 'quantity_on_hand' => 20, 'cost_price' => 5, 'sale_price' => 10, 'ecommerce_visible' => true]);
        $payload = ['type' => 'sale', 'contact_id' => $contact->id, 'document_date' => now('Asia/Dhaka')->toDateString(), 'payment_type' => 'due', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]]];

        return [$product, $payload];
    }

    private function invoiceUsage(User $owner, int $count): void
    {
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Existing customer', 'type' => 'both']);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['organization_id' => $owner->organization_id, 'number' => 'EXISTING-'.$owner->organization_id.'-'.$i, 'type' => 'sale', 'contact_id' => $contact->id, 'document_date' => now()->toDateString(), 'subtotal' => 10, 'total' => 10, 'created_by' => $owner->id];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('documents')->insert($chunk);
        }
    }

    private function companyPayload(User $owner, string $plan, ?array $limits = null): array
    {
        $payload = ['name' => $owner->organization->name, 'plan_name' => $plan, 'subscription_status' => 'active', 'owner_name' => $owner->name, 'owner_email' => $owner->email, 'owner_active' => true];
        if ($limits !== null) {
            $payload['package_limits'] = $limits;
        }

        return $payload;
    }

    #[TestWith(['Starter', 1, 2, 1000])]
    #[TestWith(['Standard', 2, 5, 5000])]
    #[TestWith(['Premium', 5, 20, null])]
    public function test_existing_and_new_companies_receive_package_defaults(string $plan, int $branches, int $users, ?int $invoices): void
    {
        $owner = $this->owner($plan);
        $this->actingAs($owner)->getJson('/api/session')->assertOk()
            ->assertJsonPath('subscription.package_limits', ['branches' => $branches, 'users' => $users, 'sale_invoices' => $invoices])
            ->assertJsonPath('subscription.package_usage', ['branches' => 1, 'users' => 1, 'sale_invoices' => 0]);
        $superadmin = User::factory()->superadmin()->create();
        $this->actingAs($superadmin)->postJson('/api/clients', ['name' => 'New company', 'plan_name' => $plan, 'subscription_status' => 'active', 'owner_name' => 'New owner', 'owner_email' => 'new-owner@example.test', 'owner_password' => 'Password123'])
            ->assertCreated()->assertJsonPath('effective_package_limits', ['branches' => $branches, 'users' => $users, 'sale_invoices' => $invoices]);
    }

    #[TestWith(['Starter', 1])]
    #[TestWith(['Standard', 2])]
    #[TestWith(['Premium', 5])]
    public function test_active_branch_creation_stops_at_the_plan_limit(string $plan, int $limit): void
    {
        $owner = $this->owner($plan);
        $superadmin = User::factory()->superadmin()->create();
        $this->actingAs($superadmin);
        for ($index = 1; $index < $limit; $index++) {
            $this->postJson('/api/clients/'.$owner->organization_id.'/branches', $this->branchPayload('BR'.$index))->assertCreated();
        }
        $this->postJson('/api/clients/'.$owner->organization_id.'/branches', $this->branchPayload('EXTRA'))->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->assertSame($limit, Branch::where('organization_id', $owner->organization_id)->where('active', true)->count());
    }

    public function test_inactive_branch_does_not_consume_capacity_but_reactivation_is_checked(): void
    {
        $owner = $this->owner();
        $superadmin = User::factory()->superadmin()->create();
        $branch = $this->actingAs($superadmin)->postJson('/api/clients/'.$owner->organization_id.'/branches', $this->branchPayload('INACTIVE', false))->assertCreated()->json();
        $this->actingAs($owner)->putJson('/api/branches/'.$branch['id'], $this->branchPayload('INACTIVE'))->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->assertFalse(Branch::findOrFail($branch['id'])->active);
    }

    #[TestWith(['Starter', 2])]
    #[TestWith(['Standard', 5])]
    public function test_user_limit_includes_the_owner_and_blocks_new_active_accounts(string $plan, int $limit): void
    {
        $owner = $this->owner($plan);
        $this->actingAs($owner);
        for ($index = 1; $index < $limit; $index++) {
            $this->postJson('/api/users', $this->userPayload($owner, 'staff'.$index.'@example.test'))->assertCreated();
        }
        $this->postJson('/api/users', $this->userPayload($owner))->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->assertSame($limit, User::where('organization_id', $owner->organization_id)->where('active', true)->count());
    }

    public function test_deactivating_a_user_frees_capacity_and_reactivating_above_capacity_is_rejected(): void
    {
        $owner = $this->owner();
        $active = User::factory()->for($owner->organization)->create();
        $inactive = User::factory()->for($owner->organization)->create(['active' => false]);
        $this->actingAs($owner)->putJson('/api/users/'.$inactive->id, $this->userPayload($owner, $inactive->email))->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->putJson('/api/users/'.$active->id, $this->userPayload($owner, $active->email, false))->assertOk();
        $this->putJson('/api/users/'.$inactive->id, $this->userPayload($owner, $inactive->email))->assertOk();
        $this->assertTrue($inactive->fresh()->active);
        $this->assertFalse($active->fresh()->active);
    }

    #[TestWith(['Starter', 1000])]
    #[TestWith(['Standard', 5000])]
    public function test_invoice_limit_allows_the_last_invoice_then_rejects_another(string $plan, int $limit): void
    {
        $owner = $this->owner($plan);
        [$product, $payload] = $this->saleRecords($owner);
        $this->invoiceUsage($owner, $limit - 1);
        $this->actingAs($owner)->postJson('/api/documents', $payload)->assertCreated();
        $this->postJson('/api/documents', $payload)->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->assertDatabaseHas('package_usages', ['organization_id' => $owner->organization_id, 'sale_invoices' => $limit]);
        $this->assertSame('19.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('documents', $limit);
    }

    public function test_deleting_an_invoice_restores_its_allowance(): void
    {
        $owner = $this->owner('Starter', ['sale_invoices' => 1]);
        [, $payload] = $this->saleRecords($owner);
        $sale = $this->actingAs($owner)->postJson('/api/documents', $payload)->assertCreated()->json();
        $this->deleteJson('/api/documents/'.$sale['id'])->assertOk();
        $superadmin = User::factory()->superadmin()->create();
        $this->actingAs($superadmin)->postJson('/api/clients/'.$owner->organization_id.'/renew', ['months' => 12])->assertOk();
        $this->actingAs($owner->fresh())->getJson('/api/session')->assertOk()->assertJsonPath('subscription.package_usage.sale_invoices', 0);
        $this->postJson('/api/documents', $payload)->assertCreated();
        $this->postJson('/api/documents', $payload)->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->assertDatabaseHas('package_usages', ['organization_id' => $owner->organization_id, 'sale_invoices' => 1]);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_deleting_one_of_thirteen_starter_invoices_leaves_988_slots_and_ignores_old_lifetime_usage(): void
    {
        $owner = $this->owner();
        [, $payload] = $this->saleRecords($owner);
        $this->actingAs($owner);
        for ($i = 0; $i < 13; $i++) {
            $sale = $this->postJson('/api/documents', $payload)->assertCreated()->json();
        }
        DB::table('package_usages')->where('organization_id', $owner->organization_id)->update(['sale_invoices' => 1000]);
        $this->deleteJson('/api/documents/'.$sale['id'])->assertOk();
        $this->getJson('/api/session')->assertOk()
            ->assertJsonPath('subscription.package_usage.sale_invoices', 12)
            ->assertJsonPath('subscription.package_limits.sale_invoices', 1000);
        $this->postJson('/api/documents', $payload)->assertCreated();
    }

    public function test_rejected_sale_does_not_consume_invoice_capacity(): void
    {
        $owner = $this->owner('Starter', ['sale_invoices' => 1]);
        [, $payload] = $this->saleRecords($owner);
        $invalid = $payload;
        $invalid['items'][0]['quantity'] = 21;
        $this->actingAs($owner)->postJson('/api/documents', $invalid)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/documents', $payload)->assertCreated();
        $this->assertDatabaseHas('package_usages', ['organization_id' => $owner->organization_id, 'sale_invoices' => 1]);
    }

    public function test_all_sales_channels_share_one_allowance_but_returns_do_not_consume_it(): void
    {
        $owner = $this->owner('Starter', ['sale_invoices' => 2]);
        [, $payload] = $this->saleRecords($owner);
        $sale = $this->actingAs($owner)->postJson('/api/documents', [...$payload, 'sale_channel' => 'pos', 'payment_method' => 'credit', 'amount_paid' => 0])->assertCreated()->json();
        $this->postJson('/api/documents', [...$payload, 'type' => 'resale'])->assertCreated();
        $this->postJson('/api/documents', [...$payload, 'type' => 'sale_return', 'sale_id' => $sale['id']])->assertCreated();
        $this->postJson('/api/documents', $payload)->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->assertDatabaseHas('package_usages', ['organization_id' => $owner->organization_id, 'sale_invoices' => 2]);
    }

    public function test_existing_invoices_are_counted_when_initializing_usage(): void
    {
        $owner = $this->owner('Starter', ['sale_invoices' => 1]);
        [, $payload] = $this->saleRecords($owner);
        $this->actingAs($owner)->postJson('/api/documents', $payload)->assertCreated();
        DB::table('package_usages')->where('organization_id', $owner->organization_id)->delete();
        $this->postJson('/api/documents', $payload)->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_premium_is_unlimited_but_keeps_usage_for_a_future_downgrade(): void
    {
        $owner = $this->owner('Premium');
        [, $payload] = $this->saleRecords($owner);
        $this->invoiceUsage($owner, 10000);
        $this->actingAs($owner)->postJson('/api/documents', $payload)->assertCreated();
        $this->assertDatabaseHas('package_usages', ['organization_id' => $owner->organization_id, 'sale_invoices' => 10001]);
        $owner->organization->update(['plan_name' => 'Starter']);
        $this->postJson('/api/documents', $payload)->assertUnprocessable()->assertJsonValidationErrors('package_limits');
    }

    public function test_superadmin_can_raise_or_lower_company_allowances_without_removing_existing_records(): void
    {
        $owner = $this->owner();
        User::factory()->for($owner->organization)->create();
        $superadmin = User::factory()->superadmin()->create();
        $limits = ['branches' => 3, 'users' => 8, 'sale_invoices' => 15000];
        $this->actingAs($superadmin)->putJson('/api/clients/'.$owner->organization_id, $this->companyPayload($owner, 'Starter', $limits))
            ->assertOk()->assertJsonPath('effective_package_limits', $limits);
        $this->postJson('/api/clients/'.$owner->organization_id.'/branches', $this->branchPayload())->assertCreated();
        $this->putJson('/api/clients/'.$owner->organization_id, $this->companyPayload($owner, 'Starter', ['branches' => 1, 'users' => 1, 'sale_invoices' => 1]))->assertOk();
        $this->assertSame(2, Branch::where('organization_id', $owner->organization_id)->count());
        $this->assertSame(2, User::where('organization_id', $owner->organization_id)->count());
        $this->postJson('/api/clients/'.$owner->organization_id.'/branches', $this->branchPayload('THIRD'))->assertUnprocessable();
        $this->actingAs($owner->fresh())->postJson('/api/users', $this->userPayload($owner))->assertUnprocessable();
    }

    public function test_plan_change_resets_unspecified_overrides_and_null_limits_allow_unlimited(): void
    {
        $owner = $this->owner('Starter', ['users' => 9]);
        $superadmin = User::factory()->superadmin()->create();
        $this->actingAs($superadmin)->putJson('/api/clients/'.$owner->organization_id, $this->companyPayload($owner, 'Premium'))
            ->assertOk()->assertJsonPath('effective_package_limits', ['branches' => 5, 'users' => 20, 'sale_invoices' => null]);
        $limits = ['branches' => null, 'users' => null, 'sale_invoices' => null];
        $this->putJson('/api/clients/'.$owner->organization_id, $this->companyPayload($owner, 'Starter', $limits))
            ->assertOk()->assertJsonPath('effective_package_limits', $limits);
    }

    public function test_demo_company_is_exempt_even_with_explicit_low_overrides(): void
    {
        $owner = $this->owner('Starter', ['branches' => 1, 'users' => 1, 'sale_invoices' => 1]);
        $owner->organization->update(['subdomain' => 'demo']);
        $this->invoiceUsage($owner, 2);
        [, $payload] = $this->saleRecords($owner);
        $superadmin = User::factory()->superadmin()->create();
        $this->actingAs($superadmin)->postJson('/api/clients/'.$owner->organization_id.'/branches', $this->branchPayload())->assertCreated();
        $this->actingAs($owner)->postJson('/api/documents', $payload)->assertCreated();
        $this->getJson('/api/session')->assertOk()->assertJsonPath('subscription.package_limits_exempt', true)
            ->assertJsonPath('subscription.package_limits', ['branches' => null, 'users' => null, 'sale_invoices' => null]);
        $this->actingAs($superadmin)->postJson('/api/users', [...$this->userPayload($owner), 'organization_id' => $owner->organization_id])->assertCreated();
    }

    public function test_only_superadmin_can_change_company_limits_and_invalid_overrides_are_rejected(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->putJson('/api/clients/'.$owner->organization_id, $this->companyPayload($owner, 'Starter', ['users' => 99]))->assertForbidden();
        $superadmin = User::factory()->superadmin()->create();
        $this->actingAs($superadmin)->putJson('/api/clients/'.$owner->organization_id, $this->companyPayload($owner, 'Starter', ['users' => 0]))->assertUnprocessable()->assertJsonValidationErrors('package_limits.users');
        $this->assertNull($owner->organization->fresh()->package_limits);
    }

    public function test_accepting_online_order_checks_the_same_invoice_limit_and_rolls_back_on_failure(): void
    {
        $owner = $this->owner('Starter', ['sale_invoices' => 1]);
        $owner->organization->update(['ecommerce_enabled' => true]);
        [$product] = $this->saleRecords($owner);
        $this->invoiceUsage($owner, 1);
        $order = EcommerceOrder::factory()->create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $owner->organization->branches()->where('is_default', true)->value('id'),
            'items' => [['product_id' => $product->id, 'name' => $product->name, 'quantity' => 1, 'unit' => 'pc', 'unit_price' => '10.00', 'line_total' => '10.00']],
            'total' => '10.00',
        ]);
        $this->actingAs($owner)->postJson('/api/ecommerce/orders/'.$order->id.'/process', ['action' => 'accept'])->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('20.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_existing_active_user_can_be_edited_when_downgraded_below_current_usage(): void
    {
        $owner = $this->owner('Starter', ['users' => 1]);
        $staff = User::factory()->for($owner->organization)->create();
        $this->actingAs($owner)->putJson('/api/users/'.$staff->id, $this->userPayload($owner, $staff->email))->assertOk();
        $this->assertSame(2, User::where('organization_id', $owner->organization_id)->where('active', true)->count());
    }

    public function test_owner_reactivation_through_company_edit_cannot_bypass_user_capacity(): void
    {
        $owner = $this->owner();
        $owner->update(['active' => false]);
        User::factory()->count(2)->for($owner->organization)->create();
        $superadmin = User::factory()->superadmin()->create();
        $this->actingAs($superadmin)->putJson('/api/clients/'.$owner->organization_id, $this->companyPayload($owner, 'Starter'))
            ->assertUnprocessable()->assertJsonValidationErrors('package_limits');
        $this->assertFalse($owner->fresh()->active);
        $this->assertSame(2, User::where('organization_id', $owner->organization_id)->where('active', true)->count());
    }
}
