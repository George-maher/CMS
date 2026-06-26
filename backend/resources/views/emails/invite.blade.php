@extends('emails.layout')

@section('slot')
  @if ($locale === 'ar')
    <h2>دعوة للانضمام</h2>
    <p>مرحباً {{ $user->name }}،</p>
    <p>تمت دعوتك للانضمام إلى {{ config('app.name') }}. يرجى استخدام الرابط أدناه لإكمال عملية التسجيل:</p>
    <div class="btn-center">
      <a href="{{ $inviteUrl }}" class="btn btn-primary">قبول الدعوة</a>
    </div>
    <p>إذا لم تكن تتوقع هذه الدعوة، يرجى تجاهل هذه الرسالة.</p>
  @else
    <h2>You're Invited</h2>
    <p>Hello {{ $user->name }},</p>
    <p>You have been invited to join {{ config('app.name') }}. Please use the link below to complete your registration:</p>
    <div class="btn-center">
      <a href="{{ $inviteUrl }}" class="btn btn-primary">Accept Invitation</a>
    </div>
    <p>If you were not expecting this invitation, please ignore this email.</p>
  @endif
@endsection
