<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\ZarinpalPaymentStatusService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

#[Signature('app:check-pending-payments')]
#[Description('Check payment status of pending orders using Zarinpal Inquiry')]
class CheckPendingPayments extends Command
{
    public function handle(
        ZarinpalPaymentStatusService $paymentStatusService
    ): int {
        $checked = 0;
        $failed = 0;
        $skipped = 0;

        Order::query()
            ->where('status', 'pending')
            ->whereNotNull('payment_token')
            ->where('payment_token', '!=', '')
            ->chunkById(100, function ($orders) use (
                $paymentStatusService,
                &$checked,
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

                    try {
                        $result = $paymentStatusService->inquiry(
                            $order->payment_token
                        );

                        $checked++;

                        $this->line(
                            "Order #{$order->id}: "
                            . "Status={$result['status']}, "
                            . "Code={$result['code']}"
                        );
                    } catch (Throwable $exception) {
                        $failed++;

                        Log::warning(
                            'Zarinpal payment inquiry failed.',
                            [
                                'order_id' => $order->id,
                                'exception' => get_class($exception),
                                'message' => $exception->getMessage(),
                            ]
                        );

                        $this->components->warn(
                            "Inquiry failed for order #{$order->id}."
                        );
                    }
                }
            });

        $this->newLine();
        $this->info("Successfully checked: {$checked}");
        $this->info("Skipped (UUID): {$skipped}");
        $this->info("Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
