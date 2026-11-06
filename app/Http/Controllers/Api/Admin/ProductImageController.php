<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductImageController extends Controller
{
    private const MAX_FILE_SIZE_KB = 5120;

    private function imageRules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:' . self::MAX_FILE_SIZE_KB,
            ],
        ];
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate($this->imageRules());

        $path = $validated['image']->store('products', 'public');

        if (! $path) {
            return response()->json([
                'message' => 'ذخیره تصویر با خطا مواجه شد.',
            ], 500);
        }

        try {
            $image = DB::transaction(function () use ($product, $path) {
                $lockedProduct = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($product->id);

                $isPrimary = ! $lockedProduct->images()->exists();

                return $lockedProduct->images()->create([
                    'image_url' => $path,
                    'is_primary' => $isPrimary,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);

            throw $exception;
        }

        return response()->json([
            'message' => 'تصویر محصول با موفقیت آپلود شد.',
            'data' => new ProductImageResource($image),
        ], 201);
    }

    public function update(
        Request $request,
        Product $product,
        ProductImage $image
    ): JsonResponse {
        abort_unless($image->product_id === $product->id, 404);

        $validated = $request->validate($this->imageRules());

        $oldPath = $image->image_url;
        $newPath = $validated['image']->store('products', 'public');

        if (! $newPath) {
            return response()->json([
                'message' => 'ذخیره تصویر جدید با خطا مواجه شد.',
            ], 500);
        }

        try {
            $image->update([
                'image_url' => $newPath,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($newPath);

            throw $exception;
        }

        Storage::disk('public')->delete($oldPath);

        return response()->json([
            'message' => 'تصویر محصول با موفقیت ویرایش شد.',
            'data' => new ProductImageResource($image->fresh()),
        ]);
    }

    public function setPrimary(Product $product, ProductImage $image): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);

        DB::transaction(function () use ($product, $image) {
            $lockedProduct = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->id);

            $lockedProduct->images()->update([
                'is_primary' => false,
            ]);

            $lockedProduct->images()
                ->whereKey($image->id)
                ->update([
                    'is_primary' => true,
                ]);
        });

        return response()->json([
            'message' => 'تصویر اصلی محصول با موفقیت تغییر کرد.',
            'data' => new ProductImageResource($image->fresh()),
        ]);
    }

    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);

        $oldPath = $image->image_url;
        $wasPrimary = $image->is_primary;

        DB::transaction(function () use ($product, $image, $wasPrimary) {
            $lockedProduct = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->id);

            $lockedImage = $lockedProduct->images()
                ->findOrFail($image->id);

            $lockedImage->delete();

            if ($wasPrimary) {
                $nextImage = $lockedProduct->images()
                    ->orderBy('id')
                    ->first();

                if ($nextImage) {
                    $nextImage->update([
                        'is_primary' => true,
                    ]);
                }
            }
        });

        Storage::disk('public')->delete($oldPath);

        return response()->json([
            'message' => 'تصویر محصول با موفقیت حذف شد.',
        ]);
    }
}
