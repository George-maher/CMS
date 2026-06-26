@extends('emails.layout')

@section('slot')
  @if ($locale === 'ar')
    <h2>تم الرد على ملاحظاتك</h2>
    <p>مرحباً {{ $user->name }}،</p>
    <p>تم الرد على ملاحظاتك في {{ config('app.name') }}.</p>

    <div class="highlight">
      <strong>{{ __('ملاحظاتك') }}:</strong><br>
      {{ $feedbackMessage }}
    </div>

    <div class="reason-box">
      <strong>{{ __('الرد') }}:</strong><br>
      {{ $replyMessage }}
    </div>

    <p>يمكنك عرض الرد كاملاً في لوحة التحكم.</p>
  @else
    <h2>Your Feedback Has a Reply</h2>
    <p>Hello {{ $user->name }},</p>
    <p>Your feedback has received a reply on {{ config('app.name') }}.</p>

    <div class="highlight">
      <strong>Your feedback:</strong><br>
      {{ $feedbackMessage }}
    </div>

    <div class="reason-box">
      <strong>Reply:</strong><br>
      {{ $replyMessage }}
    </div>

    <p>You can view the full reply in your dashboard.</p>
  @endif
@endsection
