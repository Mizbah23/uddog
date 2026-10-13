<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\PurchaseInstallment;
use App\Models\PurchasePayment;
use App\Models\SaleInstallment;
use App\Models\SalePayment;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesPaymentTermsTest extends TestCase
{
    use RefreshDatabase;

    private function setupSale(): array
    {
        $user = User::factory()->create();
        $supplier = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Supplier', 'type' => 'supplier']);
        $customer = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        $product = Product::create(['organization_id' => $user->organization_id, 'name' => 'Payment item', 'unit' => 'pc', 'active' => true]);

        $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => '2026-09-30',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 50]],
        ])->assertCreated();

        return [$user, $customer, $product];
    }

    public function test_installment_sale_creates_an_exact_schedule_and_accepts_later_payment(): void
    {
        [$user, $customer, $product] = $this->setupSale();

        $sale = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-09-30',
            'payment_type' => 'installment', 'payment_method' => 'cash', 'down_payment' => 20,
            'installment_count' => 3, 'installment_frequency' => 'monthly', 'first_installment_date' => '2026-10-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()
            ->assertJsonPath('payment_type', 'installment')
            ->assertJsonPath('amount_paid', '20.00')
            ->assertJsonPath('balance_due', '80.00')
            ->assertJsonCount(3, 'installments')
            ->json();

        $this->assertSame(['26.66', '26.66', '26.68'], SaleInstallment::query()->where('document_id', $sale['id'])->orderBy('sequence')->pluck('amount')->all());

        $first = SaleInstallment::query()->where('document_id', $sale['id'])->orderBy('sequence')->firstOrFail();
        $this->actingAs($user)->postJson('/api/documents/'.$sale['id'].'/payments', [
            'sale_installment_id' => $first->id,
            'amount' => 26.66,
            'payment_method' => 'mobile_banking',
            'payment_date' => '2026-10-15',
        ])->assertCreated()
            ->assertJsonPath('amount_paid', '46.66')
            ->assertJsonPath('balance_due', '53.34');

        $this->assertSame('26.66', $first->fresh()->amount_paid);
        $this->assertSame(2, SalePayment::query()->where('document_id', $sale['id'])->count());
    }

    public function test_installment_sale_adds_flat_interest_to_the_financed_balance_and_schedule(): void
    {
        [$user, $customer, $product] = $this->setupSale();

        $sale = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-09-30',
            'payment_type' => 'installment', 'payment_method' => 'cash', 'down_payment' => 20,
            'interest_rate' => 10, 'installment_count' => 3, 'installment_frequency' => 'monthly',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()
            ->assertJsonPath('interest_rate', '10.00')
            ->assertJsonPath('interest_amount', '8.00')
            ->assertJsonPath('total', '108.00')
            ->assertJsonPath('balance_due', '88.00')
            ->json();

        $this->assertSame(['29.33', '29.33', '29.34'], SaleInstallment::query()->where('document_id', $sale['id'])->orderBy('sequence')->pluck('amount')->all());
    }

    public function test_pos_installments_start_from_sale_date_and_keep_the_exact_schedule_on_the_invoice(): void
    {
        [$user, $customer, $product] = $this->setupSale();

        $sale = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'sale_channel' => 'pos', 'contact_id' => $customer->id,
            'document_date' => '2026-10-08', 'payment_type' => 'installment',
            'payment_method' => 'cash', 'amount_paid' => '20.00', 'down_payment' => '20.00',
            'interest_rate' => '10', 'installment_count' => 3, 'installment_frequency' => 'weekly',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()
            ->assertJsonPath('installment_start_date', '2026-10-08')
            ->assertJsonPath('first_installment_date', '2026-10-15')
            ->assertJsonPath('interest_amount', '8.00')
            ->assertJsonPath('balance_due', '88.00')
            ->assertJsonCount(3, 'installments')
            ->json();

        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], array_column($sale['installments'], 'due_date'));
        $this->assertSame(['29.33', '29.33', '29.34'], array_column($sale['installments'], 'amount'));
        $this->assertDatabaseHas('documents', ['id' => $sale['id'], 'installment_start_date' => '2026-10-08', 'first_installment_date' => '2026-10-15']);
    }

    public function test_pos_half_monthly_installments_are_due_every_fifteen_days(): void
    {
        [$user, $customer, $product] = $this->setupSale();

        $sale = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'sale_channel' => 'pos', 'contact_id' => $customer->id,
            'document_date' => '2026-10-08', 'payment_type' => 'installment',
            'payment_method' => 'cash', 'amount_paid' => '20.00', 'down_payment' => '20.00',
            'installment_start_date' => '2026-10-10', 'installment_count' => 3,
            'installment_frequency' => 'half_monthly',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->assertJsonPath('first_installment_date', '2026-10-25')->json();

        $this->assertSame(['2026-10-25', '2026-11-09', '2026-11-24'], array_column($sale['installments'], 'due_date'));
        $this->assertSame(['26.66', '26.66', '26.68'], array_column($sale['installments'], 'amount'));
    }

    public function test_pos_monthly_installments_keep_the_original_day_when_a_month_is_shorter(): void
    {
        [$user, $customer, $product] = $this->setupSale();

        $sale = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'sale_channel' => 'pos', 'contact_id' => $customer->id,
            'document_date' => '2026-01-31', 'payment_type' => 'installment',
            'payment_method' => 'cash', 'amount_paid' => '20.00', 'down_payment' => '20.00',
            'installment_start_date' => '2026-01-31', 'installment_count' => 3,
            'installment_frequency' => 'monthly',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->json();

        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30'], array_column($sale['installments'], 'due_date'));
    }

    public function test_pos_installment_start_cannot_precede_the_sale_date(): void
    {
        [$user, $customer, $product] = $this->setupSale();

        $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'sale_channel' => 'pos', 'contact_id' => $customer->id,
            'document_date' => '2026-10-08', 'payment_type' => 'installment',
            'payment_method' => 'cash', 'amount_paid' => '20.00', 'down_payment' => '20.00',
            'installment_start_date' => '2026-10-07', 'installment_count' => 3,
            'installment_frequency' => 'weekly',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors('installment_start_date');

        $this->assertSame(0, Document::query()->where('type', 'sale')->count());
    }

    public function test_pos_only_staff_can_collect_a_pos_installment_but_not_a_regular_sale(): void
    {
        [$owner, $customer, $product] = $this->setupSale();
        $staff = User::factory()->staff()->for($owner->organization)->create(['permissions' => [Permission::PosSale->value]]);
        $baseSale = [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-10-08',
            'payment_type' => 'installment', 'payment_method' => 'cash',
            'down_payment' => '20.00', 'installment_count' => 2, 'installment_frequency' => 'weekly',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ];
        $posSale = $this->actingAs($owner)->postJson('/api/documents', [
            ...$baseSale, 'sale_channel' => 'pos', 'amount_paid' => '20.00',
        ])->assertCreated()->json();
        $regularSale = $this->actingAs($owner)->postJson('/api/documents', $baseSale)->assertCreated()->json();

        $this->actingAs($staff)->postJson('/api/documents/'.$posSale['id'].'/payments', [
            'sale_installment_id' => $posSale['installments'][0]['id'],
            'amount' => '40.00', 'payment_method' => 'cash', 'payment_date' => '2026-10-15',
        ])->assertCreated()
            ->assertJsonPath('installments.0.amount_paid', '40.00')
            ->assertJsonPath('balance_due', '40.00');
        $this->actingAs($staff)->postJson('/api/documents/'.$regularSale['id'].'/payments', [
            'sale_installment_id' => $regularSale['installments'][0]['id'],
            'amount' => '40.00', 'payment_method' => 'cash', 'payment_date' => '2026-10-15',
        ])->assertForbidden();

        $this->assertDatabaseHas('sale_installments', ['id' => $posSale['installments'][0]['id'], 'amount_paid' => '40.00']);
        $this->assertDatabaseHas('sale_installments', ['id' => $regularSale['installments'][0]['id'], 'amount_paid' => '0.00']);
    }

    public function test_full_and_due_sales_have_correct_initial_balances(): void
    {
        [$user, $customer, $product] = $this->setupSale();

        $full = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-09-30',
            'payment_type' => 'full', 'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ])->assertCreated()->assertJsonPath('amount_paid', '50.00')->assertJsonPath('balance_due', '0.00')->json();

        $due = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-09-30',
            'payment_type' => 'due',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ])->assertCreated()->assertJsonPath('payment_method', 'credit')->assertJsonPath('amount_paid', '0.00')->assertJsonPath('balance_due', '50.00')->json();

        $this->actingAs($user)->postJson('/api/documents/'.$due['id'].'/payments', [
            'amount' => 50,
            'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('payment_status', 'paid');

        $this->assertSame(1, SalePayment::query()->where('document_id', $full['id'])->count());
    }

    public function test_legacy_unpaid_sale_without_payment_type_can_still_receive_a_collection(): void
    {
        [$user, $customer, $product] = $this->setupSale();
        $sale = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-09-30',
            'payment_type' => 'due',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ])->assertCreated()->json();
        Document::query()->findOrFail($sale['id'])->update(['payment_type' => null]);

        $this->actingAs($user)->postJson('/api/documents/'.$sale['id'].'/payments', [
            'amount' => 25,
            'payment_method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('payment_type', 'due')
            ->assertJsonPath('amount_paid', '25.00')
            ->assertJsonPath('balance_due', '25.00');
    }

    public function test_purchase_payment_plans_create_supplier_payments_and_installment_schedule(): void
    {
        [$user, , $product] = $this->setupSale();
        $supplier = Contact::query()->where('organization_id', $user->organization_id)->where('type', 'supplier')->firstOrFail();
        $items = [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]];

        $full = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => '2026-09-30',
            'payment_type' => 'full', 'payment_method' => 'bank_transfer', 'items' => $items,
        ])->assertCreated()->assertJsonPath('payment_type', 'full')->assertJsonPath('amount_paid', '100.00')->assertJsonPath('balance_due', '0.00')->json();
        $this->assertSame(1, PurchasePayment::query()->where('document_id', $full['id'])->count());

        $installmentSale = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => '2026-09-30',
            'payment_type' => 'installment', 'payment_method' => 'cash', 'down_payment' => 20,
            'installment_count' => 3, 'installment_frequency' => 'monthly', 'first_installment_date' => '2026-10-15',
            'items' => $items,
        ])->assertCreated()->assertJsonPath('payment_type', 'installment')->assertJsonPath('amount_paid', '20.00')->assertJsonPath('balance_due', '80.00')->assertJsonCount(3, 'purchase_installments')->json();

        $this->assertSame(['26.66', '26.66', '26.68'], PurchaseInstallment::query()->where('document_id', $installmentSale['id'])->orderBy('sequence')->pluck('amount')->all());
        $firstInstallment = PurchaseInstallment::query()->where('document_id', $installmentSale['id'])->orderBy('sequence')->firstOrFail();
        $this->actingAs($user)->postJson('/api/purchases/'.$installmentSale['id'].'/payments', [
            'purchase_installment_id' => $firstInstallment->id,
            'amount' => 26.66,
            'payment_method' => 'mobile_banking',
            'payment_date' => '2026-10-15',
        ])->assertCreated()->assertJsonPath('document.amount_paid', '46.66')->assertJsonPath('document.balance_due', '53.34');

        $this->assertSame('26.66', $firstInstallment->fresh()->amount_paid);
        $this->assertSame(2, PurchasePayment::query()->where('document_id', $installmentSale['id'])->count());

        $due = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => '2026-09-30',
            'payment_type' => 'due', 'items' => $items,
        ])->assertCreated()->assertJsonPath('payment_type', 'due')->assertJsonPath('payment_method', 'credit')->assertJsonPath('amount_paid', '0.00')->assertJsonPath('balance_due', '100.00')->json();
        $this->assertSame(0, PurchasePayment::query()->where('document_id', $due['id'])->count());
    }
}
