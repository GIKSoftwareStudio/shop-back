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


    // بررسی امکان حذف دسته‌بندی توسط کاربر عادی
    public function test_normal_user_can_delete_category(): void
    {
        User::factory()->create([
            'is_admin' => false,
        ]);

        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $this->deleteJson("/api/admin/categories/{$category->id}")
            ->assertOk()
            ->assertJson([
                'message' => 'Category deleted successfully. Products have been preserved.',
            ]);

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }



    // بررسی امکان حذف دسته‌بندی توسط کاربر مهمان
    public function test_guest_can_delete_category(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $this->deleteJson("/api/admin/categories/{$category->id}")
            ->assertOk()
            ->assertJson([
                'message' => 'Category deleted successfully. Products have been preserved.',
            ]);

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }

}
