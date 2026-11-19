Hello {{ $name }},

@if ($approved)
{{ $facility->name }} is approved and live on NHC. You can now register patients and use everything your facility has switched on.

Sign in with this email; we'll send you a code each time.
@else
We couldn't approve {{ $facility->name }} on NHC yet.
@if ($note)

{{ $note }}
@endif

Reply to this email if you have questions.
@endif

NHC, part of Hospify
