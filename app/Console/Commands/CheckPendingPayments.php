<?php

namespace App\Console\Commands;

use AbolfazlAhmadi\LaravelZarinpal\Services\ZarinpalService;
use App\Models\Order;
use App\Services\PaymentFinalizationService;
use App\Services\ZarinpalPaymentStatusService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

#[Signature('app:check-pending-payments')]
#[Description('Check and finalize pending Zarinpal payments')]
class CheckPendingPayments extends Command
{
    public function handle(
        ZarinpalPaymentStatusService $paymentStatusService,
        ZarinpalService $zarinpal,
        PaymentFinalizationService $finalizationService
    ): int {
        $checked = 0;
        $paid = 0;
        $failed = 0;
        $skipped = 0;

        Order::query()
            ->where('status', 'pending')
            ->whereNotNull('payment_token')
            ->where('payment_token', '!=', '')
            ->chunkById(100, function ($orders) use (
                $paymentStatusService,
                $zarinpal,
                $finalizationService,
                &$checked,
                &$paid,
                &$failed,
                &$skipped
            ) {
                foreach ($orders as $order) {
                    if (Str::isUuid($order->payment_token)) {
                        $skipped++;

                        $this->components->warn(
                            "Skipping order #{$order->id}: payment token is a UUID."
                        );

                        continue;
                    }

                    $verificationToken = (string) Str::uuid();
                    $verificationStartedAt = now();
                    $staleVerificationTime = now()->subMinutes(2);

                    $claimed = Order::whereKey($order->id)
                        ->where('status', 'pending')
                        ->where(function ($query) use ($staleVerificationTime) {
                            $query->whereNull('verification_started_at')
                                ->orWhere(
                                    'verification_started_at',
                                    '<=',
                                    $staleVerificationTime
                                );
                        })
                        ->update([
                            'verification_started_at' => $verificationStartedAt,
                            'verification_token' => $verificationToken,
                        ]);

                    if ($claimed !== 1) {
                        $skipped++;

                        $this->components->warn(
                            "Skipping order #{$order->id}: verification is already in progress."
                        );

                        continue;
                    }

                    try {
                        $result = $paymentStatusService->inquiry(
                            $order->payment_token
                        );

                        $checked++;

                        $status = strtoupper(trim($result['status']));

                        $this->line(
                            "Order #{$order->id}: "
                            . "Status={$status}, "
                            . "Code={$result['code']}"
                        );

                        if ($status === 'FAILED') {
                            $finalizedOrder = $finalizationService->failPayment(
                                $order,
                                $verificationToken
                            );

                            $failed++;

                            $this->components->warn(
                                "Order #{$finalizedOrder->id}: payment failed; reservation released."
                            );

                            continue;
                        }

                        if (in_array($status, ['PAID', 'VERIFIED'], true)) {
                            $verification = $zarinpal->verify(
                                $order->payment_token,
                                (int) $order->total_amount
                            );

                            $refId = $verification['ref_id'] ?? null;

                            if (
                                (! is_string($refId) && ! is_int($refId))
                                || trim((string) $refId) === ''
                            ) {
                                throw new \RuntimeException(
                                    'Zarinpal verification returned an invalid reference ID.'
                                );
                            }

                            $finalizedOrder = $finalizationService->confirmPayment(
                                $order,
                                $refId,
                                $verificationToken
                            );

                            $paid++;

                            $this->info(
                                "Order #{$finalizedOrder->id}: payment confirmed and inventory deducted."
                            );

                            continue;
                        }

                        if ($status === 'REVERSED') {
                            $this->components->warn(
                                "Order #{$order->id}: payment is reversed; manual review required."
                            );
                        } elseif ($status === 'IN_BANK') {
                            $this->line(
                                "Order #{$order->id}: payment is still in progress."
                            );
                        } else {
                            $this->components->warn(
                                "Order #{$order->id}: unknown payment status; kept pending."
                            );
                        }

                        $this->releaseVerificationLock(
                            $order,
                            $verificationToken
                        );
                    } catch (Throwable $exception) {
                        $this->releaseVerificationLock(
                            $order,
                            $verificationToken
                        );

                        $failed++;

                        Log::warning(
                            'Pending payment processing failed.',
                            [
                                'order_id' => $order->id,
                                'exception_class' => get_class($exception),
                                'message' => $exception->getMessage(),
                            ]
                        );

                        $this->components->warn(
                            "Processing failed for order #{$order->id}; reservation was kept."
                        );
                    }
                }
            });

        $this->newLine();
        $this->info("Successfully checked: {$checked}");
        $this->info("Payments confirmed: {$paid}");
        $this->info("Payments failed: {$failed}");
        $this->info("Skipped: {$skipped}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function releaseVerificationLock(
        Order $order,
        string $verificationToken
    ): void {
        Order::whereKey($order->id)
            ->where('status', 'pending')
            ->where('verification_token', $verificationToken)
            ->update([
                'verification_started_at' => null,
                'verification_token' => null,
            ]);
    }
}
