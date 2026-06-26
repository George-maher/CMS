@extends('emails.layout')

@section('slot')
  @if ($locale === 'ar')
    <h2>إعادة تعيين كلمة المرور</h2>
    <p>مرحباً {{ $user->name }}،</p>
    <p>لقد تلقينا طلباً لإعادة تعيين كلمة المرور لحسابك في {{ config('app.name') }}.</p>
    <p>انقر على الزر أدناه لتعيين كلمة مرور جديدة:</p>
    <div class="btn-center">
      <a href="{{ $resetUrl }}" class="btn btn-primary">إعادة تعيين كلمة المرور</a>
    </div>
    <p>سينتهي صلاحية رابط إعادة التعيين خلال 60 دقيقة.</p>
    <p>إذا لم تطلب إعادة تعيين كلمة المرور، يمكنك تجاهل هذه الرسالة.</p>
  @else
    <h2>Reset Your Password</h2>
    <p>Hello {{ $user->name }},</p>
    <p>We received a request to reset the password for your {{ config('app.name') }} account.</p>
    <p>Click the button below to set a new password:</p>
    <div class="btn-center">
      <a href="{{ $resetUrl }}" class="btn btn-primary">Reset Password</a>
    </div>
    <p>This password reset link will expire in 60 minutes.</p>
    <p>If you did not request a password reset, please ignore this email.</p>
  @endif
@endsection
