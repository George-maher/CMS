@extends('emails.layout')

@section('slot')
  @if ($locale === 'ar')
    <h2>تم الموافقة على طلب تسجيل الكنيسة</h2>
    <p>أهلاً {{ $user->name }}،</p>
    <p>تهانينا! تمت الموافقة على طلب تسجيل كنيستك <strong>{{ $churchName ?? '' }}</strong>.</p>
    <p>يمكنك الآن تسجيل الدخول إلى لوحة تحكم الكنيسة والبدء في إدارة مجتمعك الكنسي.</p>
    <div class="highlight">
      <p style="margin:0;"><strong>ما يمكنك فعله الآن:</strong></p>
      <p style="margin:4px 0 0;">- إدارة الخدام والأعضاء</p>
      <p style="margin:2px 0 0;">- إعداد تتبع الحضور باستخدام رموز QR</p>
      <p style="margin:2px 0 0;">- إنشاء الأحداث والآيات اليومية</p>
      <p style="margin:2px 0 0;">- عرض التحليلات والتقارير</p>
    </div>
    <div class="btn-center">
      <a href="{{ $dashboardUrl ?? (config('app.frontend_url') . '/admin') }}" class="btn btn-primary">الذهاب إلى لوحة التحكم</a>
    </div>
    <p>نشكرك على اختيار منصتنا لخدمة مجتمع كنيستك.</p>
  @else
    <h2>Your Church Registration Has Been Approved</h2>
    <p>Congratulations, {{ $user->name }}!</p>
    <p>Your church registration for <strong>{{ $churchName ?? '' }}</strong> has been approved.</p>
    <p>You can now log in to your church's admin dashboard to start managing your community.</p>
    <div class="highlight">
      <p style="margin:0;"><strong>What you can do now:</strong></p>
      <p style="margin:4px 0 0;">- Manage servants and members</p>
      <p style="margin:2px 0 0;">- Set up attendance tracking with QR codes</p>
      <p style="margin:2px 0 0;">- Create events and daily verses</p>
      <p style="margin:2px 0 0;">- View analytics and reports</p>
    </div>
    <div class="btn-center">
      <a href="{{ $dashboardUrl ?? (config('app.frontend_url') . '/admin') }}" class="btn btn-primary">Go to Dashboard</a>
    </div>
    <p>Thank you for choosing our platform to serve your church community.</p>
  @endif
@endsection
