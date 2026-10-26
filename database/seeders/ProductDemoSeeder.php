<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductDemoSeeder extends Seeder
{
    public function run(): void
    {
        $categories = Category::query()
            ->orderBy('id')
            ->get();

        if ($categories->isEmpty()) {
            $this->command?->error('No categories found.');
            return;
        }

        DB::transaction(function () use ($categories) {
            for ($i = 1; $i <= 50; $i++) {
                $name = sprintf('DEMO-Product-%03d', $i);

                $product = Product::withTrashed()
                    ->where('name', $name)
                    ->first();

                if ($product && $product->trashed()) {
                    $this->command?->warn(
                        "Skipped deleted product: {$name}"
                    );
                    continue;
                }

                if (!$product) {
                    $category = $categories[
                    ($i - 1) % $categories->count()
                    ];

                    $product = Product::create([
                        'category_id' => $category->id,
                        'name' => $name,
                        'price' => 100000 + ($i * 25000),
                        'stock' => 5 + ($i % 20),
                    ]);

                    $this->command?->info(
                        "Created product: {$name}"
                    );
                }

                $imageCount = $product->images()->count();

                for ($j = $imageCount + 1; $j <= 3; $j++) {
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_url' => "https://placehold.co/640x480?text=DEMO-{$i}-{$j}",
                        'is_primary' => $j === 1,
                    ]);

                    $this->command?->info(
                        "Added image {$j} to {$name}"
                    );
                }
            }
        });

        $this->command?->info(
            'Demo product seeding completed.'
        );
    }
}
