<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    // لیست محصولات با فیلتر، مرتب‌سازی و صفحه‌بندی
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'gte:min_price'],

            // فیلتر موجودی
            'in_stock' => ['sometimes', 'in:0,1,true,false'],

            // مرتب‌سازی
            'sort_by' => ['sometimes', 'string', 'in:newest,oldest'],

            // صفحه‌بندی
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Product::with(['category', 'images']);

        // جست‌وجوی نام محصول
        if (!empty($filters['search'])) {
            $query->where(
                'name',
                'like',
                '%' . $filters['search'] . '%'
            );
        }

        // فیلتر دسته‌بندی
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        // حداقل قیمت
        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        // حداکثر قیمت
        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        // فقط محصولات موجود یا ناموجود
        if (array_key_exists('in_stock', $filters)) {
            $inStock = filter_var($filters['in_stock'], FILTER_VALIDATE_BOOLEAN);

            if ($inStock) {
                $query->where('stock', '>', 0);
            } else {
                $query->where('stock', '<=', 0);
            }
        }

        // مرتب‌سازی بر اساس تاریخ ایجاد محصول
        $sortDirection = ($filters['sort_by'] ?? 'newest') === 'oldest'
            ? 'asc'
            : 'desc';

        $query->orderBy('created_at', $sortDirection)
            ->orderBy('id', $sortDirection);

        // صفحه‌بندی
        $products = $query
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        $response = ProductResource::collection($products)
            ->response()
            ->getData(true);

        $response['products'] = $response['data'];
        unset($response['data']);

        return response()->json($response);
    }

    // نمایش یک محصول خاص
    public function show(Product $product)
    {
        return new ProductResource(
            $product->load(['category', 'images'])
        );
    }
}
