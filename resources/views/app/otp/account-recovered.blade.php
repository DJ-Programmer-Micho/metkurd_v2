<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ __('Account Restored') }}</title>

    <style>
        html, body {
            margin: 0 auto !important;
            padding: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background: #f4f7fb;
            font-family: Arial, Helvetica, sans-serif;
        }

        * {
            -ms-text-size-adjust: 100%;
            -webkit-text-size-adjust: 100%;
            box-sizing: border-box;
        }

        table, td {
            mso-table-lspace: 0pt !important;
            mso-table-rspace: 0pt !important;
        }

        table {
            border-spacing: 0 !important;
            border-collapse: collapse !important;
            margin: 0 auto !important;
        }

        img {
            -ms-interpolation-mode: bicubic;
        }

        a {
            text-decoration: none;
        }

        .wrapper {
            width: 100%;
            background: #f4f7fb;
            padding: 30px 15px;
        }

        .container {
            max-width: 640px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(16, 24, 40, 0.08);
        }

        .header {
            background: linear-gradient(135deg, #198754 0%, #20c997 100%);
            padding: 34px 30px;
            text-align: center;
        }

        .header h1 {
            margin: 0;
            color: #ffffff;
            font-size: 28px;
            line-height: 1.3;
            font-weight: 700;
        }

        .header p {
            margin: 10px 0 0;
            color: rgba(255,255,255,0.92);
            font-size: 14px;
            line-height: 1.6;
        }

        .body {
            padding: 34px 30px 20px;
            color: #344054;
        }

        .badge {
            display: inline-block;
            padding: 8px 14px;
            background: #ecfdf3;
            color: #067647;
            border: 1px solid #abefc6;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .2px;
            margin-bottom: 18px;
        }

        .title {
            margin: 0 0 14px;
            color: #101828;
            font-size: 24px;
            font-weight: 700;
            line-height: 1.4;
        }

        .text {
            margin: 0 0 16px;
            font-size: 15px;
            line-height: 1.8;
            color: #475467;
        }

        .card {
            background: #f9fafb;
            border: 1px solid #eaecf0;
            border-radius: 14px;
            padding: 18px 20px;
            margin: 22px 0;
        }

        .card h3 {
            margin: 0 0 10px;
            font-size: 16px;
            color: #101828;
        }

        .card p {
            margin: 0;
            font-size: 14px;
            line-height: 1.8;
            color: #475467;
        }

        .button-wrap {
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .btn {
            display: inline-block;
            padding: 13px 22px;
            background: #198754;
            color: #ffffff !important;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
        }

        .note {
            margin-top: 24px;
            font-size: 13px;
            color: #667085;
            line-height: 1.8;
        }

        .footer {
            padding: 24px 30px 30px;
            border-top: 1px solid #eaecf0;
            background: #fcfcfd;
        }

        .footer p {
            margin: 0 0 8px;
            font-size: 13px;
            line-height: 1.7;
            color: #667085;
        }

        .footer a {
            color: #198754;
        }

        @media screen and (max-width: 600px) {
            .header,
            .body,
            .footer {
                padding-left: 20px !important;
                padding-right: 20px !important;
            }

            .title {
                font-size: 21px !important;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">

            <div class="header">
                <h1>{{ __('METKURD') }}</h1>
                <p>{{ __('Account Access Restored') }}</p>
            </div>

            <div class="body">
                <span class="badge">{{ __('Account Recovered') }}</span>

                <h2 class="title">
                    {{ __('Your account is active again') }}
                </h2>

                <p class="text">
                    {{ __('Hello') }} {{ $customerName ?? __('Customer') }},
                </p>

                <p class="text">
                    {{ __('Good news - your METKURD account has been successfully restored and you can now access your dashboard and services again.') }}
                </p>

                <div class="card">
                    <h3>{{ __('Recovery Summary') }}</h3>
                    <p>
                        <strong>{{ __('Account Email:') }}</strong> {{ $customerEmail ?? 'customer@example.com' }}<br>
                        <strong>{{ __('Recovery Date:') }}</strong> {{ $recoveredAt ?? now()->format('Y-m-d H:i') }}<br>
                        <strong>{{ __('Status:') }}</strong> {{ __('Active') }}
                    </p>
                </div>

                <p class="text">
                    {{ __('Any previous restriction related to the suspension has been removed. You may continue using your subscription, storage, and available credits normally.') }}
                </p>

                <div class="button-wrap">
                    <a href="{{ $loginUrl ?? route('app.signin') }}" class="btn">
                        {{ __('Go to My Account') }}
                    </a>
                </div>

                <p class="note">
                    {{ __('If you believe this recovery was made in error, or if you still cannot access your account, please contact our support team immediately.') }}
                </p>
            </div>

            <div class="footer">
                <p><strong>{{ __('Support') }}</strong></p>
                <p>{{ __('Team Support:') }} <a href="mailto:{{ $supportEmail ?? 'support@metkurd.com' }}">{{ $supportEmail ?? 'support@metkurd.com' }}</a></p>
                <p>{{ __('Developer Support:') }} <a href="mailto:{{ $supportEmail ?? 'support@metkurd.com' }}">{{ $supportEmail ?? 'support@metkurd.com' }}</a></p>
                <p>{{ __('METKURD is an AI platform for Kurdish language tools, voice workflows, and secure account access.') }}</p>
            </div>

        </div>
    </div>
</body>
</html>
