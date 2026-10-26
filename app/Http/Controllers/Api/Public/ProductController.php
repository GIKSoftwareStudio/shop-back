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
        $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
        ]);

        $query = Product::with(['category', 'images']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        $products = $query->paginate(10)->withQueryString();

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
