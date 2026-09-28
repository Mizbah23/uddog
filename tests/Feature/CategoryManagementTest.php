<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_can_be_created_assigned_and_cannot_be_deleted_while_in_use(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $category = $this->postJson('/api/categories', [
            'name' => 'Accessories',
            'description' => 'Small add-on products',
            'active' => true,
        ])->assertCreated()
            ->assertJsonPath('products_count', 0)
            ->json();

        $this->postJson('/api/products', [
            'name' => 'Wireless Mouse',
            'category_id' => $category['id'],
            'unit' => 'pc',
            'reorder_level' => 1,
            'active' => true,
        ])->assertCreated()->assertJsonPath('category_id', $category['id']);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('0.name', 'Accessories')
            ->assertJsonPath('0.products_count', 1);

        $this->deleteJson('/api/categories/'.$category['id'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
    }

    public function test_category_names_are_company_specific_and_foreign_categories_cannot_be_assigned(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $foreignCategory = Category::create([
            'organization_id' => $second->organization_id,
            'name' => 'Electronics',
            'active' => true,
        ]);

        $this->actingAs($first)->postJson('/api/categories', [
            'name' => 'Electronics',
            'active' => true,
        ])->assertCreated();

        $this->postJson('/api/products', [
            'name' => 'Foreign category attempt',
            'category_id' => $foreignCategory->id,
            'unit' => 'pc',
            'reorder_level' => 0,
            'active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_unused_category_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $category = Category::create([
            'organization_id' => $user->organization_id,
            'name' => 'Temporary',
            'active' => true,
        ]);

        $this->actingAs($user)->deleteJson('/api/categories/'.$category->id)->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }
}
