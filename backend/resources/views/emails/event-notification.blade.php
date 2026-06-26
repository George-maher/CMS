@extends('emails.layout')

@section('slot')
  @if ($locale === 'ar')
    <h2>حدث جديد</h2>
    <p>مرحباً {{ $user->name }}،</p>
    <p>تم إنشاء حدث جديد في نظام إدارة الكنيسة.</p>
    <div class="highlight">
      <p style="margin:0;"><strong>الحدث:</strong> {{ $eventName ?? '' }}</p>
      <p style="margin:4px 0 0;"><strong>التاريخ:</strong> {{ $eventDate ?? '' }}</p>
    </div>
    <div class="btn-center">
      <a href="{{ $eventUrl ?? (config('app.frontend_url') . '/member/events') }}" class="btn btn-primary">عرض الحدث</a>
    </div>
    <p>نتمنى لك حضوراً مباركاً!</p>
  @else
    <h2>New Event</h2>
    <p>Hello {{ $user->name }},</p>
    <p>A new event has been created on Church Management System.</p>
    <div class="highlight">
      <p style="margin:0;"><strong>Event:</strong> {{ $eventName ?? '' }}</p>
      <p style="margin:4px 0 0;"><strong>Date:</strong> {{ $eventDate ?? '' }}</p>
    </div>
    <div class="btn-center">
      <a href="{{ $eventUrl ?? (config('app.frontend_url') . '/member/events') }}" class="btn btn-primary">View Event</a>
    </div>
    <p>We hope to see you there!</p>
  @endif
@endsection
