<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class OrderStatusController extends Controller
{
    /**
     * نمایش وضعیت پرداخت سفارش متعلق به کاربر واردشده.
     */
    public function show(Request $request, int $order): JsonResponse
    {
        $order = Order::query()
            ->whereKey($order)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'paid_at' => $order->paid_at,
            'ref_id' => $order->payment_reference,
        ]);
    }
}
