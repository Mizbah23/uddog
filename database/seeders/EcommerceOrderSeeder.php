<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\EcommerceOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class EcommerceOrderSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('tenancy.enabled')) {
            return;
        }
        $order = EcommerceOrder::factory()->create();
        Organization::findOrFail($order->organization_id)->update(['name' => 'Liton General Store', 'phone' => '01712345678', 'address' => 'Mirpur, Dhaka']);
        User::factory()->admin()->create(['organization_id' => $order->organization_id]);
        $category = Category::create(['organization_id' => $order->organization_id, 'name' => 'Pantry essentials', 'active' => true]);
        foreach ([['Miniket rice', 'kg', 72], ['Mustard oil', 'L', 280], ['Local honey', 'kg', 650], ['Premium tea', 'pc', 120], ['Red lentils', 'kg', 135]] as [$name, $unit, $price]) {
            Product::create(['organization_id' => $order->organization_id, 'category_id' => $category->id, 'name' => $name, 'unit' => $unit, 'cost_price' => $price / 2, 'sale_price' => $price, 'quantity_on_hand' => 30, 'active' => true, 'ecommerce_visible' => true]);
        }
    }
}
