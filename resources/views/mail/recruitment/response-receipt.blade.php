@php
    $rtl = $locale === 'ar';
    $dir = $rtl ? 'rtl' : 'ltr';
    $align = $rtl ? 'right' : 'left';
    $t = fn (string $key, array $r = []) => __('recruitment-applicant.response_receipt.'.$key, $r, $locale);
@endphp
<!DOCTYPE html>
<html dir="{{ $dir }}" lang="{{ $locale }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $t('subject', ['position' => $position]) }}</title>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #dcddde; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #dcddde;">
<tr>
<td align="center" style="padding: 24px 12px;">

<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 600px; background-color: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 4px rgba(88, 89, 91, 0.08);">

{{-- Red top accent --}}
<tr><td style="height: 4px; background-color: #BE1F24; font-size: 0; line-height: 0;">&nbsp;</td></tr>

{{-- HC Logo image --}}
<tr>
<td align="center" style="padding: 34px 40px 6px;">
<img src="{{ asset('assets/img/001_HC.png') }}" alt="Hope Center HR" width="180" height="auto" style="display: block; max-width: 180px; height: auto; border: 0;">
</td>
</tr>

{{-- Heading + intro --}}
<tr>
<td dir="{{ $dir }}" style="direction: {{ $dir }}; text-align: {{ $align }}; padding: 18px 40px 4px; font-family: 'Somar Sans', Arial, Helvetica, sans-serif; color: #58595b;">
<h1 style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 14px; font-size: 22px; font-weight: bold; color: #58595b; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;">{{ $t('heading') }}</h1>
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 14px; font-size: 15px; line-height: 1.6; color: #58595b; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;">{{ $t('greeting', ['name' => $name]) }}</p>
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0; font-size: 15px; line-height: 1.6; color: #58595b; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;">{{ $t('intro') }}</p>
</td>
</tr>

{{-- Position + submitted meta --}}
<tr>
<td dir="{{ $dir }}" style="direction: {{ $dir }}; text-align: {{ $align }}; padding: 16px 40px 0; font-family: 'Somar Sans', Arial, Helvetica, sans-serif; color: #58595b;">
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 6px; font-size: 15px; color: #58595b; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;"><strong>{{ $t('position') }}:</strong> {{ $position }}</p>
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0; font-size: 15px; color: #58595b; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;"><strong>{{ $t('submitted_at') }}:</strong> {{ $submittedAt }}</p>
</td>
</tr>

{{-- Divider --}}
<tr><td style="padding: 20px 40px 4px;"><div style="height: 1px; background-color: #dcddde; font-size: 0; line-height: 0;">&nbsp;</div></td></tr>

{{-- Responses --}}
<tr>
<td dir="{{ $dir }}" style="direction: {{ $dir }}; text-align: {{ $align }}; padding: 12px 40px 0; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;">
<h2 style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 4px; font-size: 16px; font-weight: bold; color: #BE1F24; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;">{{ $t('responses') }}</h2>
</td>
</tr>
@foreach ($responses as $response)
<tr>
<td dir="{{ $dir }}" style="direction: {{ $dir }}; text-align: {{ $align }}; padding: 12px 40px 0; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;">
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0 0 3px; font-size: 13px; font-weight: bold; color: #6d6e71; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;">{{ $response['label'] }}</p>
<p style="direction: {{ $dir }}; text-align: {{ $align }}; margin: 0; font-size: 15px; line-height: 1.55; color: #58595b; white-space: pre-line; font-family: 'Somar Sans', Arial, Helvetica, sans-serif;">{{ $response['value'] }}</p>
</td>
</tr>
@endforeach

<tr><td style="height: 28px; font-size: 0; line-height: 0;">&nbsp;</td></tr>
</table>

{{-- Muted footer outside the card --}}
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 600px;">
<tr>
<td align="center" style="padding: 18px 20px 2px; font-family: 'Somar Sans', Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.6; color: #939598; text-align: center;">
{{ $t('no_reply') }}
</td>
</tr>
<tr>
<td align="center" style="padding: 0 20px 8px; font-family: 'Somar Sans', Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.6; color: #939598; text-align: center;">
&copy; {{ date('Y') }} {{ $t('org') }}
</td>
</tr>
</table>

</td>
</tr>
</table>
</body>
</html>
