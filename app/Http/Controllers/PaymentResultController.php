<?php

namespace App\Http\Controllers;

use App\Models\Order;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use IntlDateFormatter;

class PaymentResultController extends Controller
{
    /**
     * نمایش صفحه پرداخت موفق.
     */
    public function success(Request $request): View|RedirectResponse
    {
        $order = Order::query()->findOrFail($request->integer('order'));

        // اگر سفارش هنوز پرداخت‌شده نیست، نتیجه ناموفق نمایش داده می‌شود.
        if ($order->status !== 'paid') {
            return redirect()->to(URL::signedRoute('payment.failed', [
                'order' => $order->id,
                'reason' => 'not-confirmed',
            ]));
        }

        return view('payment.success', [
            'order' => $order,
            'paidAt' => $this->formatDate($order->paid_at),
            'driverName' => $this->driverName($order->payment_driver),
        ]);
    }

    /**
     * نمایش صفحه پرداخت ناموفق یا در حال بررسی.
     */
    public function failed(Request $request): View|RedirectResponse
    {
        $order = null;

        if ($request->filled('order')) {
            $order = Order::query()->find($request->integer('order'));
        }

        // اگر سفارش در واقع پرداخت شده است، نتیجه موفق نمایش داده می‌شود.
        if ($order && $order->status === 'paid') {
            return redirect()->to(URL::signedRoute('payment.success', [
                'order' => $order->id,
            ]));
        }

        $reason = (string) $request->query('reason', 'failed');

        $messages = [
            'canceled' => 'پرداخت توسط شما لغو شد یا درگاه پرداخت آن را تأیید نکرد.',
            'invalid' => 'اطلاعات دریافتی از درگاه پرداخت معتبر نیست.',
            'not-found' => 'سفارش مربوط به این پرداخت پیدا نشد.',
            'not-payable' => 'این سفارش در وضعیت فعلی قابل پرداخت نیست.',
            'in-progress' => 'نتیجه پرداخت هنوز قطعی نشده و سفارش در حال بررسی است.',
            'uncertain' => 'تأیید پرداخت با خطا مواجه شد و نتیجه آن قطعی نیست.',
            'not-confirmed' => 'نتیجه پرداخت از سوی درگاه تأیید نشد.',
            'failed' => 'پرداخت شما انجام نشد.',
        ];

        if (! array_key_exists($reason, $messages)) {
            $reason = 'failed';
        }

        return view('payment.failed', [
            'order' => $order,
            'reason' => $reason,
            'reasonMessage' => $messages[$reason],
            'driverName' => $order
                ? $this->driverName($order->payment_driver)
                : null,
            'orderDate' => $order
                ? $this->formatDate($order->created_at)
                : null,
            'statusLabel' => $order
                ? $this->statusLabel($order->status)
                : null,
        ]);
    }

    /**
     * تبدیل تاریخ میلادی به تاریخ شمسی برای نمایش در صفحه نتیجه.
     */
    private function formatDate(?DateTimeInterface $date): ?string
    {
        if (! $date) {
            return null;
        }

        // در صورت نبودن افزونه intl، تاریخ میلادی نمایش داده می‌شود.
        if (! class_exists(IntlDateFormatter::class)) {
            return $date->format('Y/m/d H:i');
        }

        $formatter = new IntlDateFormatter(
            'fa_IR@calendar=persian',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'Asia/Tehran',
            IntlDateFormatter::TRADITIONAL,
            'yyyy/MM/dd HH:mm'
        );

        return $formatter->format($date);
    }

    /**
     * نام فارسی درگاه پرداخت.
     */
    private function driverName(?string $driver): string
    {
        return match ($driver) {
            'zarinpal' => 'زرین‌پال',
            'zibal' => 'زیبال',
            default => (string) $driver,
        };
    }

    /**
     * برچسب فارسی وضعیت سفارش.
     */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'در انتظار بررسی',
            'paid' => 'پرداخت‌شده',
            'failed' => 'ناموفق',
            'shipped' => 'ارسال‌شده',
            default => $status,
        };
    }
}
