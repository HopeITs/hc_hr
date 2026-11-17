@props(['preheader' => null])
@php
    $locale = str_replace('_', '-', app()->getLocale());
    $rtl = app()->getLocale() === 'ar';
    $dir = $rtl ? 'rtl' : 'ltr';
    $align = $rtl ? 'right' : 'left';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ config('app.name') }}</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<style>
table, td, th, p, a, h1, h2, h3, li, span, div { font-family: 'Segoe UI', Arial, sans-serif !important; }
.code-value { font-family: Consolas, 'Courier New', monospace !important; }
</style>
<![endif]-->
<style>
:root { color-scheme: light only; supported-color-schemes: light only; }
@media only screen and (max-width: 620px) {
.wrapper-cell { padding: 16px 8px !important; }
.header { padding: 28px 20px 4px !important; }
.content-cell { padding: 20px 20px 28px !important; }
.code-value { font-size: 28px !important; letter-spacing: 6px !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body dir="{{ $dir }}">
@if (filled($preheader))
{{-- The inbox preview line. The filler keeps clients from appending body text (a code, say) after it. --}}
<div class="preheader" style="display: none; max-height: 0; max-width: 0; overflow: hidden; opacity: 0; mso-hide: all; font-size: 1px; line-height: 1px; color: #dcddde;">{{ $preheader }}{!! str_repeat('&#847;&zwnj;&nbsp;', 100) !!}</div>
@endif
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" bgcolor="#dcddde" dir="{{ $dir }}">
<tr>
<td class="wrapper-cell" align="center">
<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table class="card" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" align="center">
<tr>
<td class="card-frame" bgcolor="#ffffff">
<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
{!! $header ?? '' !!}
<tr>
<td class="content-cell" dir="{{ $dir }}" align="{{ $align }}" style="text-align: {{ $align }};">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>
</table>
{!! $footer ?? '' !!}
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</table>
</body>
</html>
