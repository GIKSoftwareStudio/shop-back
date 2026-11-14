<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentFinalizationService
{
    /**
     * Confirm a successful payment and deduct reserved inventory.
     */
    public function confirmPayment(
        Order $order,
        string|int $refId,
        string $verificationToken
    ): Order {
        return DB::transaction(function () use (
            $order,
            $refId,
            $verificationToken
        ) {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                throw new RuntimeException('Order not found.');
            }

            if ($lockedOrder->status === 'paid') {
                return $lockedOrder;
            }

            if (
                $lockedOrder->status !== 'pending'
                || ! hash_equals(
                    (string) $lockedOrder->verification_token,
                    $verificationToken
                )
            ) {
                throw new RuntimeException(
                    'Payment verification lock is invalid or expired.'
                );
            }

            if ($lockedOrder->stock_status === 'reserved') {
                foreach ($lockedOrder->items as $item) {
                    $deducted = Product::withTrashed()
                        ->whereKey($item->product_id)
                        ->where('stock', '>=', $item->quantity)
                        ->where('reserved_stock', '>=', $item->quantity)
                        ->decrement('stock', $item->quantity);

                    if ($deducted !== 1) {
                        throw new RuntimeException(
                            "Inventory deduction failed for product {$item->product_id}."
                        );
                    }

                    $released = Product::withTrashed()
                        ->whereKey($item->product_id)
                        ->where('reserved_stock', '>=', $item->quantity)
                        ->decrement('reserved_stock', $item->quantity);

                    if ($released !== 1) {
                        throw new RuntimeException(
                            "Inventory reservation release failed for product {$item->product_id}."
                        );
                    }
                }

                $stockStatus = 'deducted';
            } elseif ($lockedOrder->stock_status === 'legacy_deducted') {
                $stockStatus = 'legacy_deducted';
            } else {
                throw new RuntimeException(
                    "Unexpected stock status for order {$lockedOrder->id}."
                );
            }

            $lockedOrder->update([
                'status' => 'paid',
                'stock_status' => $stockStatus,
                'payment_reference' => (string) $refId,
                'paid_at' => now(),
                'verification_started_at' => null,
                'verification_token' => null,
            ]);

            return $lockedOrder->fresh();
        });
    }



    /**
     * Mark a conclusively failed payment and release inventory.
     */
    public function failPayment(
        Order $order,
        string $verificationToken
    ): Order {
        return DB::transaction(function () use (
            $order,
            $verificationToken
        ) {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                throw new RuntimeException('Order not found.');
            }

            if ($lockedOrder->status === 'failed') {
                return $lockedOrder;
            }

            if (
                $lockedOrder->status !== 'pending'
                || ! hash_equals(
                    (string) $lockedOrder->verification_token,
                    $verificationToken
                )
            ) {
                throw new RuntimeException(
                    'Payment verification lock is invalid or expired.'
                );
            }

            if ($lockedOrder->stock_status === 'reserved') {
                foreach ($lockedOrder->items as $item) {
                    $released = Product::withTrashed()
                        ->whereKey($item->product_id)
                        ->where('reserved_stock', '>=', $item->quantity)
                        ->decrement('reserved_stock', $item->quantity);

                    if ($released !== 1) {
                        throw new RuntimeException(
                            "Inventory reservation release failed for product {$item->product_id}."
                        );
                    }
                }
            } elseif ($lockedOrder->stock_status === 'legacy_deducted') {
                foreach ($lockedOrder->items as $item) {
                    $restored = Product::withTrashed()
                        ->whereKey($item->product_id)
                        ->increment('stock', $item->quantity);

                    if ($restored !== 1) {
                        throw new RuntimeException(
                            "Legacy inventory restoration failed for product {$item->product_id}."
                        );
                    }
                }
            } elseif ($lockedOrder->stock_status !== 'legacy') {
                throw new RuntimeException(
                    "Unexpected stock status for order {$lockedOrder->id}."
                );
            }

            $lockedOrder->update([
                'status' => 'failed',
                'stock_status' => in_array(
                    $lockedOrder->stock_status,
                    ['reserved', 'legacy_deducted'],
                    true
                ) ? 'released' : 'legacy',
                'verification_started_at' => null,
                'verification_token' => null,
            ]);

            return $lockedOrder->fresh();
        });
    }
}
