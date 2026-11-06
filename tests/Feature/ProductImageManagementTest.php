<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function createProduct(): Product
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 10,
        ]);
    }

    public function test_admin_can_upload_product_image(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product = $this->createProduct();

        $response = $this->post(
            "/api/admin/products/{$product->id}/images",
            [
                'image' => UploadedFile::fake()->image('product.jpg'),
            ],
            ['Accept' => 'application/json']
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true);

        $image = ProductImage::firstOrFail();

        Storage::disk('public')->assertExists($image->image_url);

        $this->assertDatabaseHas('product_images', [
            'id' => $image->id,
            'product_id' => $product->id,
            'is_primary' => true,
        ]);
    }

    public function test_first_uploaded_image_is_primary_and_next_image_is_not(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('first.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated()->assertJsonPath('data.is_primary', true);

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('second.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated()->assertJsonPath('data.is_primary', false);

        $this->assertDatabaseCount('product_images', 2);
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'is_primary' => true,
        ]);
    }

    public function test_normal_user_cannot_upload_product_image(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($user);

        $product = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('product.jpg')],
            ['Accept' => 'application/json']
        )->assertForbidden();

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_guest_cannot_upload_product_image(): void
    {
        $product = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('product.jpg')],
            ['Accept' => 'application/json']
        )->assertUnauthorized();

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_upload_rejects_invalid_file_type(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->create('document.pdf', 100)],
            ['Accept' => 'application/json']
        )->assertUnprocessable();

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_upload_rejects_images_larger_than_five_megabytes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('large.jpg')->size(5121)],
            ['Accept' => 'application/json']
        )->assertUnprocessable();

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_admin_can_replace_product_image(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('old.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $image = ProductImage::firstOrFail();
        $oldPath = $image->image_url;

        $this->put(
            "/api/admin/products/{$product->id}/images/{$image->id}",
            ['image' => UploadedFile::fake()->image('new.jpg')],
            ['Accept' => 'application/json']
        )->assertOk();

        $image->refresh();

        $this->assertNotEquals($oldPath, $image->image_url);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($image->image_url);
    }

    public function test_admin_can_change_primary_product_image(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('first.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('second.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $images = ProductImage::orderBy('id')->get();

        $this->patch(
            "/api/admin/products/{$product->id}/images/{$images[1]->id}/primary",
            [],
            ['Accept' => 'application/json']
        )->assertOk()->assertJsonPath('data.is_primary', true);

        $this->assertDatabaseHas('product_images', [
            'id' => $images[0]->id,
            'is_primary' => false,
        ]);

        $this->assertDatabaseHas('product_images', [
            'id' => $images[1]->id,
            'is_primary' => true,
        ]);
    }

    public function test_deleting_primary_image_promotes_another_image(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('first.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('second.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $images = ProductImage::orderBy('id')->get();
        $deletedPath = $images[0]->image_url;

        $this->delete(
            "/api/admin/products/{$product->id}/images/{$images[0]->id}",
            [],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->assertDatabaseMissing('product_images', [
            'id' => $images[0]->id,
        ]);

        $this->assertDatabaseHas('product_images', [
            'id' => $images[1]->id,
            'is_primary' => true,
        ]);

        Storage::disk('public')->assertMissing($deletedPath);
    }

    public function test_deleting_non_primary_image_does_not_change_primary_image(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('first.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('second.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $images = ProductImage::orderBy('id')->get();

        $this->delete(
            "/api/admin/products/{$product->id}/images/{$images[1]->id}",
            [],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->assertDatabaseHas('product_images', [
            'id' => $images[0]->id,
            'is_primary' => true,
        ]);

        $this->assertDatabaseMissing('product_images', [
            'id' => $images[1]->id,
        ]);
    }

    public function test_admin_cannot_update_or_delete_image_of_another_product(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product1 = $this->createProduct();
        $product2 = $this->createProduct();

        $this->post(
            "/api/admin/products/{$product1->id}/images",
            ['image' => UploadedFile::fake()->image('first.jpg')],
            ['Accept' => 'application/json']
        )->assertCreated();

        $image = ProductImage::firstOrFail();

        $this->put(
            "/api/admin/products/{$product2->id}/images/{$image->id}",
            ['image' => UploadedFile::fake()->image('replacement.jpg')],
            ['Accept' => 'application/json']
        )->assertNotFound();

        $this->delete(
            "/api/admin/products/{$product2->id}/images/{$image->id}",
            [],
            ['Accept' => 'application/json']
        )->assertNotFound();

        $this->assertDatabaseHas('product_images', [
            'id' => $image->id,
            'product_id' => $product1->id,
        ]);
    }

    public function test_product_image_api_returns_full_public_url(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $product = $this->createProduct();

        $response = $this->post(
            "/api/admin/products/{$product->id}/images",
            ['image' => UploadedFile::fake()->image('product.jpg')],
            ['Accept' => 'application/json']
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.image_url',
                fn ($url) => str_starts_with($url, '/storage/products/')
                    || str_starts_with($url, 'http://localhost/storage/products/')
                    || str_starts_with($url, 'http://localhost:8000/storage/products/')
            );
    }
}
