@extends('emails.layout')

@section('slot')
  @if ($locale === 'ar')
    <h2>مرحباً بك في {{ config('app.name') }}</h2>
    <p>مرحباً {{ $user->name }}،</p>
    <p>نرحب بك في {{ config('app.name') }}. تم إنشاء حسابك بنجاح.</p>
    <p>يمكنك الآن تسجيل الدخول والبدء في استخدام خدماتنا:</p>
    <div class="btn-center">
      <a href="{{ $loginUrl ?? (config('app.frontend_url') . '/login') }}" class="btn btn-primary">تسجيل الدخول</a>
    </div>
    <p>إذا كان لديك أي استفسار، يرجى التواصل مع فريق الدعم.</p>
  @else
    <h2>Welcome to {{ config('app.name') }}</h2>
    <p>Hello {{ $user->name }},</p>
    <p>Welcome to {{ config('app.name') }}. Your account has been created successfully.</p>
    <p>You can now log in and start using our services:</p>
    <div class="btn-center">
      <a href="{{ $loginUrl ?? (config('app.frontend_url') . '/login') }}" class="btn btn-primary">Log In</a>
    </div>
    <p>If you have any questions, please contact our support team.</p>
  @endif
@endsection
