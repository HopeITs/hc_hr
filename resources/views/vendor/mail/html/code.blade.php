@props(['code', 'minutes' => null])
{{-- A one-time code, large and monospaced in its own box. Always left-to-right,
     even in an Arabic email, so the digits read in the order they are typed.
     Pair it with a preheader: without one, the inbox preview can show the code. --}}
<table class="code" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
<tr>
<td align="center">
<table class="code-box" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" bgcolor="#ffffff">
<tr>
<td class="code-value" align="center" dir="ltr">{{ $code }}</td>
</tr>
</table>
@if ($minutes)
<p class="code-expires">{{ trans_choice('mail.code.expires', $minutes) }}</p>
@endif
</td>
</tr>
</table>
