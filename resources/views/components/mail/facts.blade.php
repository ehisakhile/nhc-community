@props(['rows'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;border:1px solid #EEEAF3;border-radius:12px;">
@foreach ($rows as $label => $value)
  <tr>
    <td style="padding:10px 14px;font-size:13px;color:#8A8496;width:34%;{{ $loop->last ? '' : 'border-bottom:1px solid #EEEAF3;' }}">{{ $label }}</td>
    <td style="padding:10px 14px;font-size:14px;color:#17151F;font-weight:600;{{ $loop->last ? '' : 'border-bottom:1px solid #EEEAF3;' }}">{{ $value ?: '—' }}</td>
  </tr>
@endforeach
</table>
