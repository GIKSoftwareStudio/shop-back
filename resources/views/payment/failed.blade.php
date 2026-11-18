@extends('layouts.payment-result')

@section('title', 'پرداخت ناموفق')

@section('content')
    <div class="icon icon--danger" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 6 6 18M6 6l12 12"/>
        </svg>
    </div>

    <h1>پرداخت ناموفق بود</h1>

    <p class="lead">{{ $reasonMessage }}</p>

    @if ($order)
        <dl class="receipt">
            <div class="row">
                <dt>شماره سفارش</dt>
                <dd class="ltr">#{{ $order->id }}</dd>
            </div>
            <div class="row">
                <dt>مبلغ سفارش</dt>
                <dd>{{ number_format((float) $order->total_amount) }} تومان</dd>
            </div>
            @if ($driverName)
                <div class="row">
                    <dt>درگاه پرداخت</dt>
                    <dd>{{ $driverName }}</dd>
                </div>
            @endif
            @if ($statusLabel)
                <div class="row">
                    <dt>وضعیت سفارش</dt>
                    <dd>{{ $statusLabel }}</dd>
                </div>
            @endif
            @if ($orderDate)
                <div class="row">
                    <dt>تاریخ ثبت سفارش</dt>
                    <dd>{{ $orderDate }}</dd>
                </div>
            @endif
        </dl>
    @endif

    @if (! in_array($reason, ['invalid', 'invalid-link', 'not-found'], true))
        <div class="note">
            <strong>اگر مبلغی از حساب شما کسر شده باشد</strong>
            این مبلغ حداکثر تا ۷۲ ساعت آینده به حساب شما بازگردانده می‌شود.
        </div>
    @endif

    <a class="button button--danger" href="{{ url('/') }}">بازگشت به فروشگاه</a>
@endsection
