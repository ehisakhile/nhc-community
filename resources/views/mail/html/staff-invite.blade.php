@extends('mail.html.layout', ['title' => "You've been added to $facility", 'preview' => "$facility added you to NHC as $role"])
@section('body')
<x-mail.heading>You've been added to {{ $facility }}</x-mail.heading>
<p style="margin:0;">Hello {{ $name }}, {{ $facility }} has added you to NHC as <strong>{{ $role }}</strong>.</p>
<p style="margin:14px 0 0;">Sign in with this email address. We'll send you a code each time, so there's no password to remember.</p>
<x-mail.button :href="config('nhc.web_url').'/login'">Sign in to NHC</x-mail.button>
<x-mail.note>If you weren't expecting this, you can ignore this email.</x-mail.note>
@endsection
