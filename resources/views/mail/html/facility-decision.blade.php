@extends('mail.html.layout', ['title' => $approved ? "$facility->name is live on NHC" : 'About your NHC registration'])
@section('body')
@if ($approved)
<x-mail.heading>{{ $facility->name }} is live on NHC</x-mail.heading>
<p style="margin:0;">Hello {{ $name }}, you're approved. You can now register patients and use everything your facility has switched on.</p>
<x-mail.button :href="config('nhc.web_url').'/login'">Open your portal</x-mail.button>
<x-mail.note>Sign in with this email; we'll send you a code each time.</x-mail.note>
@else
<x-mail.heading>We couldn't approve {{ $facility->name }} yet</x-mail.heading>
<p style="margin:0;">Hello {{ $name }}, thanks for registering on NHC.</p>
@if ($note)
<p style="margin:14px 0 0;padding:14px 16px;background:#FBF2F7;border-radius:12px;">{{ $note }}</p>
@endif
<x-mail.note>Reply to this email or write to support@hospify.cloud if you have questions.</x-mail.note>
@endif
@endsection
