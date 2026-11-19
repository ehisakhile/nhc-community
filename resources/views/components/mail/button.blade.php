@props(['href'])
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 8px;">
  <tr><td style="background:#0F0D1A;border-radius:12px;">
    <a href="{{ $href }}" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:700;color:#FFFFFF;text-decoration:none;">{{ $slot }}</a>
  </td></tr>
</table>
