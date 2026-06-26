@extends('emails.layout')

@section('slot')
  <h2>{{ $subjectText }}</h2>
  <p>{{ $user->name }},</p>
  <div class="highlight">
    {!! nl2br(e($content ?? '')) !!}
  </div>
  @if ($locale === 'ar')
    <p>يمكنك عرض جميع الإشعارات في لوحة التحكم.</p>
  @else
    <p>You can view all notifications in your dashboard.</p>
  @endif
@endsection
