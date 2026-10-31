<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\EcommerceOrder;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EcommerceOrder> */
class EcommerceOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->state(['ecommerce_enabled' => true, 'subdomain' => 'shop-'.strtolower(Str::random(8))]),
            'branch_id' => fn (array $attributes) => Branch::query()->where('organization_id', $attributes['organization_id'])->where('is_default', true)->value('id'),
            'checkout_token' => (string) Str::uuid(), 'number' => 'WEB-'.strtoupper(Str::random(12)), 'status' => 'pending',
            'customer_name' => fake()->name(), 'phone' => '01712345678', 'address' => fake()->address(),
            'total' => '50.00',
            'items' => function (array $attributes): array {
                $product = Product::create(['organization_id' => $attributes['organization_id'], 'name' => 'Demo online product', 'unit' => 'pc', 'cost_price' => 10, 'sale_price' => 25, 'quantity_on_hand' => 20, 'active' => true, 'ecommerce_visible' => true]);

                return [['product_id' => $product->id, 'name' => $product->name, 'unit' => 'pc', 'quantity' => 2, 'unit_price' => '25.00', 'line_total' => '50.00']];
            },
        ];
    }
}
