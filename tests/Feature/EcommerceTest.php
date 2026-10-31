<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EcommerceOrder;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EcommerceTest extends TestCase
{
    use RefreshDatabase;

    private function records(): array
    {
        config(['tenancy.base_domain' => 'uddog-soft.com']);
        $owner = User::factory()->admin()->create();
        $owner->organization->update(['subdomain' => 'liton', 'ecommerce_enabled' => true, 'phone' => '01712345678', 'address' => 'Liton Market, Dhaka']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Online product', 'unit' => 'pc', 'cost_price' => 10, 'sale_price' => 25, 'active' => true, 'ecommerce_visible' => true, 'quantity_on_hand' => 20]);

        return [$owner, $product];
    }

    private function checkout(Product $product): array
    {
        return ['checkout_token' => (string) Str::uuid(), 'customer_name' => 'Online Customer', 'phone' => '01812345678', 'address' => 'Dhaka delivery address', 'expected_total' => '50.00', 'items' => [['product_id' => $product->id, 'quantity' => 2]]];
    }

    public function test_public_storefront_exposes_only_visible_active_tenant_products_and_no_private_fields(): void
    {
        [$owner, $product] = $this->records();
        Product::create(['organization_id' => $owner->organization_id, 'name' => 'Hidden product', 'unit' => 'pc', 'cost_price' => 8, 'sale_price' => 20, 'ecommerce_visible' => false]);
        Product::create(['organization_id' => $owner->organization_id, 'name' => 'Inactive product', 'unit' => 'pc', 'cost_price' => 8, 'sale_price' => 20, 'ecommerce_visible' => true, 'active' => false]);
        $other = User::factory()->admin()->create();
        Product::create(['organization_id' => $other->organization_id, 'name' => 'Other tenant product', 'unit' => 'pc', 'cost_price' => 8, 'sale_price' => 20, 'ecommerce_visible' => true]);
        $this->get('http://liton.uddog-soft.com/ecomm')->assertOk()->assertSee($owner->organization->name);
        $this->getJson('http://liton.uddog-soft.com/ecomm/catalog')->assertOk()->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $product->id)->assertJsonPath('products.0.price', '25.00')
            ->assertJsonPath('products.0.in_stock', true)->assertJsonPath('store.phone', '01712345678')
            ->assertJsonMissingPath('products.0.cost_price')->assertJsonMissingPath('products.0.quantity_on_hand')
            ->assertJsonMissingPath('products.0.organization_id');
    }

    #[TestWith(['disabled'])]
    #[TestWith(['paused'])]
    #[TestWith(['expired'])]
    public function test_disabled_or_inactive_store_returns_404_for_catalog_page_and_checkout(string $state): void
    {
        [$owner, $product] = $this->records();
        $owner->organization->update(match ($state) {
            'disabled' => ['ecommerce_enabled' => false], 'paused' => ['access_paused' => true],
            default => ['subscription_ends_at' => now()->subDay()->toDateString()],
        });
        $this->get('http://liton.uddog-soft.com/ecomm')->assertNotFound();
        $this->getJson('http://liton.uddog-soft.com/ecomm/catalog')->assertNotFound();
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertNotFound();
        $this->assertDatabaseCount('ecommerce_orders', 0);
    }

    public function test_central_and_unknown_hosts_never_expose_an_arbitrary_store(): void
    {
        $this->records();
        $this->getJson('http://uddog-soft.com/ecomm/catalog')->assertNotFound();
        $this->getJson('http://unknown.uddog-soft.com/ecomm/catalog')->assertNotFound();
    }

    public function test_checkout_creates_pending_order_at_server_price_without_touching_stock_or_recording_payment(): void
    {
        [, $product] = $this->records();
        $data = $this->checkout($product);
        $response = $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $data)->assertCreated()->assertJsonPath('status', 'pending')->assertJsonMissingPath('customer_name');
        $this->assertDatabaseHas('ecommerce_orders', ['number' => $response->json('number'), 'total' => 50, 'customer_name' => 'Online Customer']);
        $this->assertSame('20.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $data)->assertCreated()->assertJsonPath('number', $response->json('number'));
        $this->assertDatabaseCount('ecommerce_orders', 1);
    }

    #[TestWith(['quantity', 0])]
    #[TestWith(['quantity', 0.5])]
    #[TestWith(['quantity', 21])]
    #[TestWith(['expected_total', 1])]
    #[TestWith(['product_id', 999999])]
    public function test_invalid_or_tampered_checkout_is_rejected(string $field, int|float $value): void
    {
        [, $product] = $this->records();
        $payload = $this->checkout($product);
        if ($field === 'expected_total') {
            $payload[$field] = $value;
        } else {
            $payload['items'][0][$field] = $value;
        }
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('ecommerce_orders', 0);
        $this->assertSame('20.000', $product->fresh()->quantity_on_hand);
    }

    public function test_hidden_or_other_tenant_products_cannot_be_ordered_by_guessing_ids(): void
    {
        [$owner, $product] = $this->records();
        $product->update(['ecommerce_visible' => false]);
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertUnprocessable();
        $other = User::factory()->admin()->create();
        $product->update(['ecommerce_visible' => true, 'organization_id' => $other->organization_id]);
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertUnprocessable();
        $this->assertDatabaseCount('ecommerce_orders', 0);
    }

    public function test_owner_visibility_switch_controls_catalog_and_is_blocked_if_feature_is_disabled(): void
    {
        [$owner, $product] = $this->records();
        $this->actingAs($owner)->putJson('/api/products/'.$product->id.'/ecommerce', ['ecommerce_visible' => false])->assertOk()->assertJsonPath('ecommerce_visible', false);
        $this->getJson('http://liton.uddog-soft.com/ecomm/catalog')->assertOk()->assertJsonCount(0, 'products');
        $owner->organization->update(['ecommerce_enabled' => false]);
        $this->putJson('/api/products/'.$product->id.'/ecommerce', ['ecommerce_visible' => true])->assertNotFound();
        $this->assertFalse($product->fresh()->ecommerce_visible);
    }

    public function test_owner_accepts_order_once_and_creates_due_sale_with_stock_deduction(): void
    {
        [$owner, $product] = $this->records();
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertCreated();
        $order = EcommerceOrder::firstOrFail();
        $this->actingAs($owner)->getJson('/api/ecommerce/orders')->assertOk()->assertJsonPath('data.0.id', $order->id);
        $this->postJson('/api/ecommerce/orders/'.$order->id.'/process', ['action' => 'accept'])->assertOk()->assertJsonPath('status', 'accepted');
        $this->assertSame('18.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('documents', ['id' => $order->fresh()->document_id, 'type' => 'sale', 'total' => 50, 'amount_paid' => 0]);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->postJson('/api/ecommerce/orders/'.$order->id.'/process', ['action' => 'accept'])->assertUnprocessable();
        $this->assertDatabaseCount('documents', 1);
        $this->deleteJson('/api/documents/'.$order->fresh()->document_id)->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'This sale belongs to an online order and cannot be deleted. Use a sale correction instead.');
        $this->assertSame('18.000', $product->fresh()->quantity_on_hand);
    }

    public function test_cancelled_order_does_not_affect_stock(): void
    {
        [$owner, $product] = $this->records();
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertCreated();
        $order = EcommerceOrder::firstOrFail();
        $this->actingAs($owner)->postJson('/api/ecommerce/orders/'.$order->id.'/process', ['action' => 'cancel'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame('20.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_insufficient_stock_at_confirmation_rolls_back_sale_and_leaves_order_pending(): void
    {
        [$owner, $product] = $this->records();
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertCreated();
        $product->update(['quantity_on_hand' => 1]);
        ProductStock::where('product_id', $product->id)->update(['quantity_on_hand' => 1]);
        $order = EcommerceOrder::firstOrFail();
        $this->actingAs($owner)->postJson('/api/ecommerce/orders/'.$order->id.'/process', ['action' => 'accept'])->assertUnprocessable();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame('1.000', $product->fresh()->quantity_on_hand);
    }

    public function test_order_management_requires_owner_and_scopes_records_to_the_owner_organization(): void
    {
        [$owner, $product] = $this->records();
        $this->getJson('/api/ecommerce/orders')->assertUnauthorized();
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertCreated();
        $order = EcommerceOrder::firstOrFail();
        $staff = User::factory()->create(['organization_id' => $owner->organization_id]);
        $this->actingAs($staff)->getJson('/api/ecommerce/orders')->assertForbidden();
        $other = User::factory()->admin()->create();
        $other->organization->update(['ecommerce_enabled' => true]);
        $this->actingAs($other)->getJson('/api/ecommerce/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/ecommerce/orders/'.$order->id.'/process', ['action' => 'accept'])->assertNotFound();
        $this->putJson('/api/products/'.$product->id.'/ecommerce', ['ecommerce_visible' => false])->assertNotFound();
    }

    public function test_shared_stock_products_use_default_branch_availability_and_gold_items_are_quote_only(): void
    {
        [$owner, $product] = $this->records();
        $variant = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Shared stock listing', 'unit' => 'pc', 'cost_price' => 0, 'sale_price' => 30, 'ecommerce_visible' => true, 'stock_source_product_id' => $product->id, 'stock_source_quantity' => 2]);
        $this->getJson('http://liton.uddog-soft.com/ecomm/catalog')->assertOk()->assertJsonCount(1, 'products');
        $owner->organization->update(['shared_stock_sales' => true]);
        $this->getJson('http://liton.uddog-soft.com/ecomm/catalog')->assertOk()->assertJsonCount(2, 'products')->assertJsonPath('products.1.in_stock', true);
        $product->update(['is_gold_item' => true]);
        $this->getJson('http://liton.uddog-soft.com/ecomm/catalog')->assertOk()->assertJsonPath('products.0.price', null)->assertJsonPath('products.0.requires_quote', true);
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertUnprocessable();
    }

    public function test_only_superadmin_can_enable_ecommerce_and_owner_cannot_enable_it_through_product_save(): void
    {
        $admin = User::factory()->superadmin()->create();
        $this->actingAs($admin)->postJson('/api/clients', ['name' => 'Online enabled store', 'subdomain' => 'shop-new', 'subscription_status' => 'active', 'ecommerce_enabled' => true, 'owner_name' => 'Shop Owner', 'owner_email' => 'shop-new@example.test', 'owner_password' => 'Password123'])->assertCreated()->assertJsonPath('ecommerce_enabled', true);
        [$owner, $product] = $this->records();
        $owner->organization->update(['ecommerce_enabled' => false]);
        $this->actingAs($owner)->putJson('/api/products/'.$product->id, ['name' => $product->name, 'unit' => 'pc', 'cost_price' => 10, 'sale_price' => 25, 'reorder_level' => 0, 'ecommerce_visible' => false, 'ecommerce_enabled' => true])->assertUnprocessable();
        $this->assertFalse($owner->organization->fresh()->ecommerce_enabled);
    }

    public function test_superadmin_can_disable_and_reenable_store_without_losing_product_visibility(): void
    {
        [$owner, $product] = $this->records();
        $company = $owner->organization;
        $superadmin = User::factory()->superadmin()->create();
        $payload = ['name' => $company->name, 'subdomain' => 'liton', 'subscription_status' => 'active', 'owner_name' => $owner->name, 'owner_email' => $owner->email, 'owner_active' => true, 'ecommerce_enabled' => false];
        $this->actingAs($superadmin)->putJson('/api/clients/'.$company->id, $payload)->assertOk()->assertJsonPath('ecommerce_enabled', false);
        $this->getJson('http://liton.uddog-soft.com/ecomm/catalog')->assertNotFound();
        $this->assertTrue($product->fresh()->ecommerce_visible);
        $this->putJson('/api/clients/'.$company->id, [...$payload, 'ecommerce_enabled' => true])->assertOk()->assertJsonPath('ecommerce_enabled', true);
        $this->getJson('http://liton.uddog-soft.com/ecomm/catalog')->assertOk()->assertJsonPath('products.0.id', $product->id);
        $this->actingAs($owner)->putJson('/api/clients/'.$company->id, $payload)->assertForbidden();
    }

    public function test_checkout_requires_customer_delivery_details_and_does_not_accept_duplicate_items(): void
    {
        [, $product] = $this->records();
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', [])->assertUnprocessable()->assertJsonValidationErrors(['customer_name', 'phone', 'address', 'items', 'checkout_token']);
        $data = $this->checkout($product);
        $data['items'][] = $data['items'][0];
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $data)->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->assertDatabaseCount('ecommerce_orders', 0);
    }

    public function test_hidden_product_cannot_be_accepted_and_customer_records_roll_back_on_failure(): void
    {
        [$owner, $product] = $this->records();
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertCreated();
        $product->update(['ecommerce_visible' => false]);
        $order = EcommerceOrder::firstOrFail();
        $this->actingAs($owner)->postJson('/api/ecommerce/orders/'.$order->id.'/process', ['action' => 'accept'])->assertUnprocessable();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('20.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_storefront_uses_default_branch_stock_instead_of_aggregate_company_stock(): void
    {
        [$owner, $product] = $this->records();
        ProductStock::where('product_id', $product->id)->update(['quantity_on_hand' => 0]);
        $otherBranch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Second branch', 'code' => 'SECOND', 'active' => true]);
        ProductStock::create(['organization_id' => $owner->organization_id, 'branch_id' => $otherBranch->id, 'product_id' => $product->id, 'quantity_on_hand' => 20]);
        $this->getJson('http://liton.uddog-soft.com/ecomm/catalog')->assertOk()->assertJsonPath('products.0.in_stock', false);
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertUnprocessable();
        $this->assertDatabaseCount('ecommerce_orders', 0);
    }

    public function test_order_cannot_be_accepted_after_superadmin_disables_ecommerce(): void
    {
        [$owner, $product] = $this->records();
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $this->checkout($product))->assertCreated();
        $order = EcommerceOrder::firstOrFail();
        $owner->organization->update(['ecommerce_enabled' => false]);
        $this->actingAs($owner)->postJson('/api/ecommerce/orders/'.$order->id.'/process', ['action' => 'accept'])->assertNotFound();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('20.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_oversized_order_amount_is_rejected_before_integer_overflow(): void
    {
        [, $product] = $this->records();
        $product->update(['sale_price' => '999999999999.99', 'quantity_on_hand' => 1000000]);
        ProductStock::where('product_id', $product->id)->update(['quantity_on_hand' => 1000000]);
        $data = $this->checkout($product);
        $data['items'][0]['quantity'] = 999999;
        $this->postJson('http://liton.uddog-soft.com/ecomm/orders', $data)->assertUnprocessable()->assertJsonPath('errors.items.0', 'The order total exceeds the supported range.');
        $this->assertDatabaseCount('ecommerce_orders', 0);
    }
}
