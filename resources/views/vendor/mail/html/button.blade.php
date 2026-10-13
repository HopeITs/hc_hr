@props([
    'url',
    'align' => 'center',
])
{{-- Bulletproof button: the cell carries the colour and (via mso-padding-alt) the
     padding in Outlook, which ignores padding on links. --}}
<table class="action" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table cellpadding="0" cellspacing="0" border="0" role="presentation" align="{{ $align }}">
<tr>
<td class="button-cell" align="center" bgcolor="#be1f24">
<a href="{{ $url }}" class="button" target="_blank" rel="noopener">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
