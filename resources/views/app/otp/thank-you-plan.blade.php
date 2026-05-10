<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <title>METKURD | Thank you for your plan subscription</title>
</head>
<body style="margin:0;padding:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Your service plan is now active.</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f6fb;margin:0;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,0.08);">
          <tr>
            <td style="padding:24px 32px;background:#0f172a;">
              <table role="presentation" width="100%">
                <tr>
                  <td>
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                      <tr>
                        <td style="padding-right:10px;vertical-align:middle;">
                          <img src="{{ asset('app/logo/white_logo.png') }}" alt="METKURD" height="26" style="display:block;border:0;outline:none;text-decoration:none;">
                        </td>
                        <td style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.5px;vertical-align:middle;">METKURD</td>
                      </tr>
                    </table>
                  </td>
                  <td align="right" style="font-size:12px;color:#cbd5e1;">Subscription confirmed</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;">Subscription confirmed</div>
              <h1 style="margin:16px 0 12px;font-size:30px;line-height:1.25;color:#0f172a;">Thank you for subscribing to your plan.</h1>
              <p style="margin:0 0 18px;font-size:16px;line-height:1.7;color:#475569;">Hi {{ $customerName }}, we've successfully activated your service plan. Your account is now ready to use the plan benefits included with your subscription.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:22px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;">
                <tr>
                  <td style="padding:20px;">
                    <div style="font-size:14px;color:#64748b;margin-bottom:8px;">Plan details</div>
                    <div style="font-size:24px;font-weight:700;color:#0f172a;margin-bottom:8px;">{{ $planName }}</div>
                    <div style="font-size:15px;color:#475569;line-height:1.8;">Billing cycle: {{ $billingCycle }}<br>Credits included: {{ $creditsIncluded }}<br>Activated on: {{ $activatedOn }}</div>
                  </td>
                </tr>
              </table>
              @if (!empty($actionUrl))
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0 0;">
                  <tr>
                    <td style="border-radius:12px;background:#2563eb;">
                      <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 22px;color:#ffffff;text-decoration:none;font-weight:700;">{{ $actionLabel ?? 'Open Dashboard' }}</a>
                    </td>
                  </tr>
                </table>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding:0 32px 32px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-top:1px solid #e5e7eb;padding-top:18px;">
                <tr>
                  <td style="font-size:13px;line-height:1.7;color:#64748b;">
                    Need help? Contact <a href="mailto:{{ $supportEmail }}" style="color:#2563eb;text-decoration:none;">{{ $supportEmail }}</a><br>
                    This message was sent by METKURD for account and billing activity.
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
