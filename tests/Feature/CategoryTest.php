<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_category_without_deleting_products(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        Sanctum::actingAs($admin);

        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 7,
        ]);

        $this->deleteJson("/api/admin/categories/{$category->id}")
            ->assertOk()
            ->assertJson([
                'message' => 'Category deleted successfully. Products have been preserved.',
            ]);

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Test Product',
            'category_id' => null,
            'stock' => 7,
        ]);
    }

    public function test_normal_user_cannot_delete_category(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        Sanctum::actingAs($user);

        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $this->deleteJson("/api/admin/categories/{$category->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
        ]);
    }

    public function test_guest_cannot_delete_category(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $this->deleteJson("/api/admin/categories/{$category->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
        ]);
    }
}
