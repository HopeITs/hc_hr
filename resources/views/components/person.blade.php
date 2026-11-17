{{--
    A person's name that opens their profile drawer (position.profile-drawer, in
    the app layout). The click is handled by resources/js/person-link.js, so this
    works in a table cell, a tile or a plain page. Who may be seen is still decided
    on the server (EmployeePolicy::viewDirectory). Never put it inside a link.
--}}
@props(['id' => null, 'name' => ''])

@if ($id)
    <button type="button" data-person="{{ (int) $id }}"
            aria-label="{{ __('general.view-profile-of', ['name' => $name]) }}"
            {{ $attributes->merge(['class' => 'max-w-full cursor-pointer truncate text-start hover:underline']) }}><bdi>{{ $name }}</bdi></button>
@else
    <span {{ $attributes }}><bdi>{{ $name }}</bdi></span>
@endif
