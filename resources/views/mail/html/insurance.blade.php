@extends('mail.html.layout', ['title' => $heading])
@section('body')
<x-mail.heading>{{ $heading }}</x-mail.heading>
@foreach ($lines as $line)
<p style="margin:0 0 10px;">{{ $line }}</p>
@endforeach
<x-mail.button :href="$url">{{ $button }}</x-mail.button>
@if ($note)
<x-mail.note>{{ $note }}</x-mail.note>
@endif
@endsection
