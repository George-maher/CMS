@extends('emails.layout')

@section('slot')
  @if ($locale === 'ar')
    <h2>تحديث طلب تسجيل الكنيسة</h2>
    <p>عزيزي {{ $user->name }}،</p>
    <p>تمت مراجعة طلب تسجيل كنيستك.</p>
    <p>نأسف، لم يتم الموافقة على طلبك في هذا الوقت.</p>
    <div class="reason-box">
      <strong>سبب الرفض:</strong><br>
      {{ $reason ?? '' }}
    </div>
    <p>إذا كان لديك أي استفسار أو ترغب في إعادة التقديم بمعلومات مصححة، يرجى التواصل مع فريق الدعم.</p>
  @else
    <h2>Church Registration Update</h2>
    <p>Dear {{ $user->name }},</p>
    <p>Your church registration application has been reviewed.</p>
    <p>Unfortunately, your application could not be approved at this time.</p>
    <div class="reason-box">
      <strong>Reason for rejection:</strong><br>
      {{ $reason ?? '' }}
    </div>
    <p>If you have any questions or would like to reapply with corrected information, please contact our support team.</p>
  @endif
@endsection
