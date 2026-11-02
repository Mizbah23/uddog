<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DuePartialPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $type, float $paid): array
    {
        $owner = User::factory()->admin()->create();
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Partial payment contact', 'type' => 'both']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Partial payment item', 'unit' => 'pc', 'quantity_on_hand' => 10, 'cost_price' => 50, 'sale_price' => 100]);
        $this->actingAs($owner);

        return ['type' => $type, 'contact_id' => $contact->id, 'document_date' => now('Asia/Dhaka')->toDateString(), 'payment_type' => 'due', 'payment_method' => 'bank_transfer', 'amount_paid' => $paid, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]]];
    }

    #[TestWith(['sale', 0])]
    #[TestWith(['sale', 25.50])]
    #[TestWith(['sale', 100])]
    #[TestWith(['purchase', 0])]
    #[TestWith(['purchase', 25.50])]
    #[TestWith(['purchase', 100])]
    public function test_due_invoice_records_initial_payment_and_remaining_balance(string $type, float $paid): void
    {
        $payload = $this->payload($type, $paid);
        $invoice = $this->postJson('/api/documents', $payload)->assertCreated()
            ->assertJsonPath('payment_type', 'due')->assertJsonPath('amount_paid', number_format($paid, 2, '.', ''))
            ->assertJsonPath('balance_due', number_format(100 - $paid, 2, '.', ''))
            ->assertJsonPath('payment_method', $paid > 0 ? 'bank_transfer' : 'credit')->json();
        $table = $type === 'sale' ? 'sale_payments' : 'purchase_payments';
        $this->assertDatabaseCount($table, $paid > 0 ? 1 : 0);
        if ($paid > 0) {
            $this->assertDatabaseHas($table, ['document_id' => $invoice['id'], 'amount' => $paid, 'payment_method' => 'bank_transfer']);
        }
        $this->assertDatabaseCount('sale_installments', 0);
        $this->assertDatabaseCount('purchase_installments', 0);
    }

    #[TestWith(['sale'])]
    #[TestWith(['purchase'])]
    public function test_remaining_due_can_be_paid_later_without_duplicate_initial_payments(string $type): void
    {
        $payload = $this->payload($type, 25.50);
        $invoice = $this->postJson('/api/documents', $payload)->assertCreated()->json();
        $route = $type === 'sale' ? 'documents' : 'purchases';
        $this->postJson('/api/'.$route.'/'.$invoice['id'].'/payments', ['amount' => 74.50, 'payment_method' => 'bank_transfer', 'payment_date' => now('Asia/Dhaka')->toDateString()])
            ->assertCreated();
        $this->assertDatabaseHas('documents', ['id' => $invoice['id'], 'amount_paid' => 100, 'total' => 100]);
        $this->assertDatabaseCount($type === 'sale' ? 'sale_payments' : 'purchase_payments', 2);
    }

    #[TestWith(['sale'])]
    #[TestWith(['purchase'])]
    public function test_overpayment_or_credit_method_cannot_record_a_partial_payment(string $type): void
    {
        $payload = $this->payload($type, 101);
        $this->postJson('/api/documents', $payload)->assertUnprocessable()->assertJsonValidationErrors('amount_paid');
        $this->postJson('/api/documents', [...$payload, 'amount_paid' => -1])->assertUnprocessable()->assertJsonValidationErrors('amount_paid');
        $this->postJson('/api/documents', [...$payload, 'amount_paid' => 25, 'payment_method' => 'credit'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('purchase_payments', 0);
        $this->assertSame('10.000', Product::first()->quantity_on_hand);
    }
}
