<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    // ۱. لیست محصولات (صفحه‌بندی شده + قابلیت فیلتر با category_id)

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'gte:min_price'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Product::with(['category', 'images']);

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        $products = $query
            ->orderBy('id', 'desc')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        $response = ProductResource::collection($products)
            ->response()
            ->getData(true);

        $response['products'] = $response['data'];
        unset($response['data']);

        return response()->json($response);
    }


    // ۲. نمایش یک محصول خاص به همراه عکس‌هایش
    public function show(Product $product)
    {
        return new ProductResource($product->load(['category', 'images']));
    }
}
