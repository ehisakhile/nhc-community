{{-- NHC's emails: black ink, the logo's magenta leaf as the one accent. Inline styles and tables, for every mail app. --}}
@php($web = config('nhc.web_url'))
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $title ?? 'NHC' }}</title>
</head>
<body style="margin:0;padding:0;background:#F4F2F7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#17151F;">
@isset($preview)<div style="display:none;max-height:0;overflow:hidden;">{{ $preview }}</div>@endisset
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F2F7;">
  <tr><td align="center" style="padding:32px 16px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
      <tr><td align="left" style="padding:0 4px 18px;">
        <a href="{{ $web }}" style="text-decoration:none;"><img src="{{ $web }}/logo.png" width="120" height="53" alt="Nested Healthcare" style="display:block;border:0;"></a>
      </td></tr>
      <tr><td style="background:#FFFFFF;border-radius:18px;overflow:hidden;border:1px solid #E7E3EE;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr><td style="height:5px;background:#C63D85;line-height:5px;font-size:0;">&nbsp;</td></tr>
          <tr><td style="padding:34px 32px 30px;font-size:15.5px;line-height:1.6;color:#2B2735;">
            @yield('body')
          </td></tr>
        </table>
      </td></tr>
      <tr><td style="padding:20px 8px 0;font-size:12px;line-height:1.6;color:#8A8496;">
        Nested Healthcare (NHC), part of Hospify. One health record across your hospitals.<br>
        Questions? <a href="mailto:support@hospify.cloud" style="color:#C63D85;text-decoration:none;">support@hospify.cloud</a>
        &nbsp;·&nbsp; <a href="{{ $web }}" style="color:#8A8496;">{{ preg_replace('#^https?://#', '', $web) }}</a>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
