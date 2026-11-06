<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function prices(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $productIds = array_map('intval', $validated['product_ids']);

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'price', 'stock']);

        $foundIds = $products
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $unavailableIds = array_values(
            array_diff($productIds, $foundIds)
        );

        return response()->json([
            'products' => $products,
            'unavailable_product_ids' => $unavailableIds,
        ]);
    }
}
