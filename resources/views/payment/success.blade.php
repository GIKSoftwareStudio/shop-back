@extends('layouts.payment-result')

@section('title', 'پرداخت موفق')

@section('content')
    <div class="icon icon--success" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 6 9 17l-5-5"/>
        </svg>
    </div>

    <h1>پرداخت با موفقیت انجام شد</h1>

    <p class="lead">
        سفارش شما با موفقیت ثبت شد و پرداخت آن تأیید گردید. از خرید شما سپاسگزاریم.
    </p>

    <dl class="receipt">
        <div class="row">
            <dt>شماره سفارش</dt>
            <dd class="ltr">#{{ $order->id }}</dd>
        </div>
        <div class="row">
            <dt>مبلغ پرداخت</dt>
            <dd>{{ number_format((float) $order->total_amount) }} تومان</dd>
        </div>
        <div class="row">
            <dt>شماره پیگیری</dt>
            <dd class="ltr">{{ $order->payment_reference }}</dd>
        </div>
        <div class="row">
            <dt>درگاه پرداخت</dt>
            <dd>{{ $driverName }}</dd>
        </div>
        @if ($paidAt)
            <div class="row">
                <dt>تاریخ پرداخت</dt>
                <dd>{{ $paidAt }}</dd>
            </div>
        @endif
    </dl>

    <a class="button button--success" href="{{ url('/') }}">بازگشت به فروشگاه</a>
@endsection
