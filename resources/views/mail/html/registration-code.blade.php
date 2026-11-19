@extends('mail.html.layout', ['title' => 'Confirm your email', 'preview' => "Your code to register $facility is $code"])
@section('body')
<x-mail.heading>Confirm your email</x-mail.heading>
<p style="margin:0;">Hello {{ $name }}, enter this code to register <strong>{{ $facility }}</strong> on NHC.</p>
<x-mail.code>{{ $code }}</x-mail.code>
<p style="margin:0;">It works for 10 minutes. Once it's in, your portal opens and you can start setting up.</p>
<x-mail.note>If you didn't ask for it, ignore this email: nothing is registered without the code.</x-mail.note>
@endsection
