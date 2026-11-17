@props(['title' => null])
{{-- A red-edged warning note, for security messages. The edge sits on the reading
     side: physical left/right, because Outlook ignores logical properties. --}}
@php($side = app()->getLocale() === 'ar' ? 'right' : 'left')
<table class="notice" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
<tr>
<td class="notice-content" bgcolor="#dcddde" style="border-{{ $side }}: 4px solid #be1f24; text-align: {{ $side }};">
@if (filled($title))
<p class="notice-title">{{ $title }}</p>
@endif
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
