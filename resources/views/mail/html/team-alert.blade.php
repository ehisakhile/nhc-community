@extends('mail.html.layout', ['title' => $heading])
@section('body')
<x-mail.heading>{{ $heading }}</x-mail.heading>
<p style="margin:0;">{{ $intro }}</p>
<x-mail.facts :rows="$rows" />
@isset($next)<p style="margin:0;">{{ $next }}</p>@endisset
@endsection
