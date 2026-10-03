<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Models\SaleInstallment;
use App\Models\SalePayment;
use App\Models\User;
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
}
