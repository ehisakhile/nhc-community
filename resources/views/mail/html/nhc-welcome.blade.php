@extends('mail.html.layout', ['title' => 'See your health records', 'preview' => 'Link your NHC record in the Hospify app'])
@section('body')
<x-mail.heading>Your health records, in one place</x-mail.heading>
<p style="margin:0;">Hello {{ $firstName }}, you've been registered on NHC at a partner hospital. To see your records and prescriptions and book visits:</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0;">
@foreach (['Get the Hospify app and sign in (email, Google or Apple all work).', 'Open NHC and tap "Link my NHC record".', 'Enter the code we text to the phone number you gave the hospital.'] as $i => $step)
  <tr>
    <td valign="top" style="padding:6px 12px 6px 0;"><span style="display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:12px;background:#C63D85;color:#FFFFFF;font-size:13px;font-weight:800;">{{ $i + 1 }}</span></td>
    <td style="padding:6px 0;font-size:15px;">{{ $step }}</td>
  </tr>
@endforeach
</table>
<x-mail.button href="https://hospify.africa">Get the Hospify app</x-mail.button>
<x-mail.note>If this wasn't you, ignore this email. Nothing can be linked without the code to your phone.</x-mail.note>
@endsection
