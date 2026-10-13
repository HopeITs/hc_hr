@props(['code', 'minutes' => null])
{{ $code }}
@if ($minutes)
{{ trans_choice('mail.code.expires', $minutes) }}
@endif
