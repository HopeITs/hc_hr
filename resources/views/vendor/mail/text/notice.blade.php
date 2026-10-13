@props(['title' => null])
@if (filled($title))
{{ $title }}
@endif
{{ $slot }}
