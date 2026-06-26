@extends('emails.layout')

@section('slot')
  @if ($locale === 'ar')
    <h2>تم تقديم طلب التسجيل</h2>
    <p>مرحباً {{ $user->name }}،</p>
    <p>شكراً لتقديم طلب تسجيل كنيستك في {{ config('app.name') }}.</p>
    <p>سيتم مراجعة طلبك من قبل فريق الإدارة. سنقوم بإعلامك عند اتخاذ القرار.</p>
    <div class="highlight">
      <p style="margin:0;"><strong>حالة الطلب:</strong> قيد المراجعة</p>
    </div>
    <p>يمكنك متابعة حالة طلبك من خلال تسجيل الدخول إلى حسابك.</p>
  @else
    <h2>Registration Submitted</h2>
    <p>Hello {{ $user->name }},</p>
    <p>Thank you for submitting your church registration to {{ config('app.name') }}.</p>
    <p>Your application will be reviewed by our administration team. We will notify you once a decision has been made.</p>
    <div class="highlight">
      <p style="margin:0;"><strong>Status:</strong> Pending Review</p>
    </div>
    <p>You can track your application status by logging into your account.</p>
  @endif
@endsection
