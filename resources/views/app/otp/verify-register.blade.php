<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <title>METKURD | Your verification code</title>
</head>
<body style="margin:0;padding:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Use this secure code to continue your sign-in.</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f6fb;margin:0;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,0.08);">
          <tr>
            <td style="padding:24px 32px;background:#0f172a;">
              <table role="presentation" width="100%">
                <tr>
                  <td style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.5px;">METKURD</td>
                  <td align="right" style="font-size:12px;color:#cbd5e1;">Email verification</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              <div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:12px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;">Email verification</div>
              <h1 style="margin:16px 0 12px;font-size:30px;line-height:1.25;color:#0f172a;">Your verification code is ready.</h1>
              <p style="margin:0 0 18px;font-size:16px;line-height:1.7;color:#475569;">Use the one-time code below to continue. For your security, do not share it with anyone.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0;">
                <tr>
                  <td align="center" style="padding:24px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:18px;">
                    <div style="font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">One-time password</div>
                    <div style="font-size:42px;font-weight:800;letter-spacing:10px;color:#0f172a;">{{ $otpCode }}</div>
                  </td>
                </tr>
              </table>
              <p style="margin:0 0 10px;font-size:15px;line-height:1.7;color:#475569;">This code expires in <strong>{{ $expiresMinutes }} {{ \Illuminate\Support\Str::plural('minute', $expiresMinutes) }}</strong>. If you did not request it, you can safely ignore this email.</p>
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
