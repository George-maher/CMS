@extends('emails.layout')

@section('slot')
  @if ($locale === 'ar')
    <h2>تم تسجيل حضورك</h2>
    <p>مرحباً {{ $user->name }}،</p>
    <p>تم تسجيل حضورك بنجاح.</p>
    <div class="highlight">
      <p style="margin:0;"><strong>الفصل:</strong> {{ $className ?? '' }}</p>
      <p style="margin:4px 0 0;"><strong>التاريخ:</strong> {{ $date ?? '' }}</p>
    </div>
    <p>نشكرك على حضورك. نتمنى لك يوماً مباركاً!</p>
  @else
    <h2>Attendance Recorded</h2>
    <p>Hello {{ $user->name }},</p>
    <p>Your attendance has been recorded successfully.</p>
    <div class="highlight">
      <p style="margin:0;"><strong>Class:</strong> {{ $className ?? '' }}</p>
      <p style="margin:4px 0 0;"><strong>Date:</strong> {{ $date ?? '' }}</p>
    </div>
    <p>Thank you for attending. Have a blessed day!</p>
  @endif
@endsection
