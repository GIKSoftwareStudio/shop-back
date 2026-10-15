<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ۱. ساخت ۱۰ دسته‌بندی
        $categories = Category::factory(10)->create();

        // ۲. ساخت ۵۰ محصول و پخش کردن آن‌ها بین ۱۰ دسته‌بندی بالا
        Product::factory(50)
            ->recycle($categories) // محصولات را به همان ۱۰ دسته‌بندی ساخته شده وصل می‌کند
            ->create()
            ->each(function (Product $product) {
                // ۳. ساخت ۲ یا ۳ عکس برای هر محصول به صورت تصادفی
                $imageCount = rand(2, 3);

                for ($i = 0; $i < $imageCount; $i++) {
                    ProductImage::factory()->create([
                        'product_id' => $product->id,
                        // عکس اول (i == 0) را به عنوان عکس اصلی (is_primary = true) قرار می‌دهیم
                        'is_primary' => $i === 0,
                    ]);
                }
            });
    }
}
