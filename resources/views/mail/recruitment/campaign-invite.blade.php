@php
    $blocks = [
        'ar' => ['dir' => 'rtl', 'align' => 'right'],
        'en' => ['dir' => 'ltr', 'align' => 'left'],
    ];
    $font = "'Somar Sans', Arial, Helvetica, sans-serif";
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ __('recruitment-applicants.invite_mail.subject', ['position' => $position['en']], 'en') }}</title>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #dcddde; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #dcddde;">
<tr>
<td align="center" style="padding: 24px 12px;">

<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 600px; background-color: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 4px rgba(88, 89, 91, 0.08);">

<tr><td style="height: 4px; background-color: #BE1F24; font-size: 0; line-height: 0;">&nbsp;</td></tr>

<tr>
<td align="center" style="padding: 34px 40px 6px;">
<img src="{{ asset('assets/img/001_HC.png') }}" alt="Hope Center HR" width="180" height="auto" style="display: block; max-width: 180px; height: auto; border: 0;">
</td>
</tr>

@foreach ($blocks as $locale => $layout)
@php
    $t = fn (string $key, array $r = []) => __('recruitment-applicants.invite_mail.'.$key, $r, $locale);
    $dir = $layout['dir'];
    $align = $layout['align'];
    $title = $position[$locale] ?: $position['en'];
@endphp
@if (! $loop->first)
<tr><td style="padding: 20px 40px 4px;"><div style="height: 1px; background-color: #dcddde; font-size: 0; line-height: 0;">&nbsp;</div></td></tr>
@endif

<tr>
<td dir="{{ $dir }}" style="direction: {{ $dir }}; text-align: {{ $align }}; padding: 18px 40px 4px; font-family: {!! $font !!}; color: #58595b;">
<h1 style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 14px; font-size: 22px; font-weight: bold; color: #58595b; font-family: {!! $font !!};">{{ $t('heading') }}</h1>
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 14px; font-size: 15px; line-height: 1.6; color: #58595b; font-family: {!! $font !!};">{{ $t('greeting', ['name' => $name]) }}</p>
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 14px; font-size: 15px; line-height: 1.6; color: #58595b; font-family: {!! $font !!};">{{ $t('intro', ['position' => $title]) }}</p>
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 6px; font-size: 15px; color: #58595b; font-family: {!! $font !!};"><strong>{{ $t('use_email') }}:</strong> <span dir="ltr" style="unicode-bidi: embed;">{{ $email }}</span></p>
@if ($closes)
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 6px; font-size: 15px; color: #58595b; font-family: {!! $font !!};"><strong>{{ $t('closes') }}:</strong> {{ $closes->copy()->locale($locale)->translatedFormat('d M Y') }}</p>
@endif
</td>
</tr>

<tr>
<td align="{{ $align }}" style="padding: 12px 40px 8px; text-align: {{ $align }};">
<a href="{{ $url }}" style="display: inline-block; background-color: #BE1F24; color: #ffffff; font-family: {!! $font !!}; font-size: 15px; font-weight: bold; text-decoration: none; padding: 12px 28px; border-radius: 8px;">{{ $t('apply') }}</a>
</td>
</tr>
@endforeach

<tr>
<td dir="ltr" style="padding: 14px 40px 0; font-family: {!! $font !!}; font-size: 12px; line-height: 1.6; color: #939598; text-align: center; word-break: break-all;">
<a href="{{ $url }}" style="color: #939598;">{{ $url }}</a>
</td>
</tr>

<tr><td style="height: 28px; font-size: 0; line-height: 0;">&nbsp;</td></tr>
</table>

<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 600px;">
<tr>
<td align="center" style="padding: 18px 20px 2px; font-family: {!! $font !!}; font-size: 12px; line-height: 1.6; color: #939598; text-align: center;">
{{ __('recruitment-applicants.invite_mail.no_reply', [], 'ar') }}<br>{{ __('recruitment-applicants.invite_mail.no_reply', [], 'en') }}
</td>
</tr>
<tr>
<td align="center" style="padding: 0 20px 8px; font-family: {!! $font !!}; font-size: 12px; line-height: 1.6; color: #939598; text-align: center;">
&copy; {{ date('Y') }} Christian Hope Center
</td>
</tr>
</table>

</td>
</tr>
</table>
</body>
</html>
