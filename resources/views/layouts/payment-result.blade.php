<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('title', 'نتیجه پرداخت')</title>

    <style>
        :root {
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --success: #16a34a;
            --success-soft: #dcfce7;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(circle at 15% 20%, rgba(22, 163, 74, .10), transparent 35%),
                radial-gradient(circle at 85% 80%, rgba(37, 99, 235, .08), transparent 35%),
                var(--bg);
            color: var(--text);
            font-family: "Vazirmatn", "Segoe UI", Tahoma, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .card {
            width: 100%;
            max-width: 470px;
            padding: 42px 36px 30px;
            border: 1px solid rgba(226, 232, 240, .9);
            border-radius: 28px;
            background: var(--card);
            box-shadow: 0 24px 60px -24px rgba(15, 23, 42, .25);
        }

        .icon {
            display: grid;
            place-items: center;
            width: 88px;
            height: 88px;
            margin: 0 auto 22px;
            border-radius: 50%;
            animation: pop .45s ease-out both;
        }

        .icon svg {
            width: 42px;
            height: 42px;
        }

        .icon--success {
            color: var(--success);
            background: var(--success-soft);
            box-shadow: 0 0 0 10px rgba(22, 163, 74, .08);
        }

        .icon--danger {
            color: var(--danger);
            background: var(--danger-soft);
            box-shadow: 0 0 0 10px rgba(220, 38, 38, .07);
        }

        @keyframes pop {
            from {
                opacity: 0;
                transform: scale(.7);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        h1 {
            margin: 0 0 10px;
            font-size: 1.5rem;
            line-height: 1.6;
            text-align: center;
        }

        .lead {
            max-width: 36ch;
            margin: 0 auto 26px;
            color: var(--muted);
            font-size: .95rem;
            line-height: 1.9;
            text-align: center;
        }

        .receipt {
            margin: 0 0 22px;
            padding: 4px 18px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: #f8fafc;
        }

        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 0;
            border-bottom: 1px dashed var(--border);
            font-size: .9rem;
        }

        .row:last-child {
            border-bottom: 0;
        }

        .row dt {
            margin: 0;
            color: var(--muted);
        }

        .row dd {
            margin: 0;
            font-weight: 600;
            text-align: left;
        }

        .ltr {
            direction: ltr;
            unicode-bidi: embed;
        }

        .note {
            margin: 0 0 22px;
            padding: 13px 15px;
            border: 1px solid #fed7aa;
            border-radius: 14px;
            background: #fff7ed;
            color: #9a3412;
            font-size: .82rem;
            line-height: 1.9;
        }

        .note strong {
            display: block;
            margin-bottom: 2px;
        }

        .button {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 50px;
            border-radius: 14px;
            color: #fff;
            font-size: .95rem;
            font-weight: 600;
            text-decoration: none;
            transition: transform .15s, background .15s, box-shadow .15s;
        }

        .button:hover {
            transform: translateY(-1px);
        }

        .button--success {
            background: var(--success);
            box-shadow: 0 10px 24px -12px rgba(22, 163, 74, .8);
        }

        .button--success:hover {
            background: #15803d;
        }

        .button--danger {
            background: var(--danger);
            box-shadow: 0 10px 24px -12px rgba(220, 38, 38, .7);
        }

        .button--danger:hover {
            background: #b91c1c;
        }

        .footer {
            margin: 18px 0 0;
            color: #94a3b8;
            font-size: .72rem;
            text-align: center;
        }

        @media (max-width: 480px) {
            body {
                padding: 16px;
            }

            .card {
                padding: 34px 22px 26px;
                border-radius: 22px;
            }

            h1 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <main class="card">
        @yield('content')

        <p class="footer">این صفحه نتیجه پرداخت شما را نمایش می‌دهد.</p>
    </main>
</body>
</html>
