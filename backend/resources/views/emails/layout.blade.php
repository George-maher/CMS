<!DOCTYPE html>
<html dir="{{ ($locale ?? 'en') === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $locale ?? 'en' }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{{ $subjectText ?? '' }}</title>
  <style>
    /* Base */
    body, table, td, p { margin: 0; padding: 0; }
    body { background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; -webkit-font-smoothing: antialiased; font-size: 16px; line-height: 1.6; color: #1f2937; }
    .email-wrapper { width: 100%; table-layout: fixed; background-color: #f4f6f9; padding: 20px 0; }
    .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .email-header { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); padding: 32px 40px 28px; text-align: center; }
    .email-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #ffffff; letter-spacing: -0.3px; }
    .email-header p { margin: 6px 0 0; font-size: 14px; color: rgba(255,255,255,0.85); }
    .email-body { padding: 32px 40px; }
    .email-body h2 { font-size: 20px; font-weight: 600; color: #111827; margin: 0 0 16px; }
    .email-body p { margin: 0 0 16px; color: #4b5563; }
    .email-body p:last-child { margin-bottom: 0; }
    .btn { display: inline-block; padding: 14px 32px; font-size: 15px; font-weight: 600; text-decoration: none; border-radius: 8px; text-align: center; margin: 8px 0 16px; transition: background-color 0.2s; }
    .btn-primary { background-color: #4f46e5; color: #ffffff !important; }
    .btn-primary:hover { background-color: #4338ca; }
    .btn-center { text-align: center; }
    .email-footer { padding: 24px 40px; background-color: #f9fafb; border-top: 1px solid #e5e7eb; text-align: center; }
    .email-footer p { font-size: 13px; color: #6b7280; margin: 4px 0; }
    .email-footer a { color: #4f46e5; text-decoration: none; }
    .divider { height: 1px; background-color: #e5e7eb; margin: 24px 0; }
    .highlight { background-color: #f3f4f6; border-radius: 8px; padding: 16px 20px; margin: 16px 0; border-left: 4px solid #4f46e5; }
    html[dir="rtl"] .highlight { border-left: none; border-right: 4px solid #4f46e5; }
    .reason-box { background-color: #fef2f2; border-radius: 8px; padding: 16px 20px; margin: 16px 0; border-left: 4px solid #ef4444; }
    html[dir="rtl"] .reason-box { border-left: none; border-right: 4px solid #ef4444; }
    @media only screen and (max-width: 600px) {
      .email-body { padding: 24px 20px !important; }
      .email-header { padding: 24px 20px !important; }
      .email-footer { padding: 20px !important; }
      .btn { display: block !important; width: 100% !important; box-sizing: border-box !important; }
    }
  </style>
</head>
<body>
  <table class="email-wrapper" role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center" style="padding: 20px 10px;">
        <table class="email-container" role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <!-- Header -->
          <tr>
            <td class="email-header">
              <h1>{{ config('app.name') }}</h1>
              <p>{{ $locale === 'ar' ? 'نظام إدارة الكنيسة' : 'Church Management System' }}</p>
            </td>
          </tr>
          <!-- Body -->
          <tr>
            <td class="email-body">
              {{ $slot ?? '' }}
            </td>
          </tr>
          <!-- Footer -->
          <tr>
            <td class="email-footer">
              <p>{{ $locale === 'ar' ? 'هذه رسالة آلية، يرجى عدم الرد عليها.' : 'This is an automated message, please do not reply.' }}</p>
              <p>&copy; {{ date('Y') }} {{ config('app.name') }}. {{ $locale === 'ar' ? 'جميع الحقوق محفوظة.' : 'All rights reserved.' }}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
