@extends('mail.html.layout', ['title' => 'Your NHC sign-in code', 'preview' => "Your code is $code"])
@section('body')
<x-mail.heading>Your sign-in code</x-mail.heading>
<p style="margin:0;">Hello {{ $name }}, use this code to sign in to NHC.</p>
<x-mail.code>{{ $code }}</x-mail.code>
<p style="margin:0;">It works for 10 minutes, once.</p>
<x-mail.note>If you didn't ask for it, ignore this email: nobody can sign in without the code. NHC never asks for it by phone.</x-mail.note>
@endsection
