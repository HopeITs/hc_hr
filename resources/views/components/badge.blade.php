{{--
    Legacy: a neutral fact ("Per month", "Yes"). Colour carried no meaning the
    words did not, and the identity has no other hue, so `color` is accepted and
    ignored; this is <x-tag variant="soft">. A status uses <x-status> instead.
--}}
@props(['color' => null])
<x-tag variant="soft" {{ $attributes->class('me-2') }}>{{ $slot }}</x-tag>
