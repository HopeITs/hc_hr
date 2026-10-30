@props([
    'id',
    // false: shown only while the field (anything inside the nearest
    // `group/field` ancestor) has focus. true: always shown, for fields that
    // are hard to get right without it (a format, a rule).
    'always' => true,
    // 'span' inside phrasing content (a <label>), where a <p> is not allowed.
    'as' => 'p',
])

@php
    $tag = $as === 'span' ? 'span' : 'p';
@endphp

{{--
    Quiet hint: one short line under a field, text-2 at 14px.

    The field points at it with aria-describedby="{{ id }}", so a screen reader
    reads it on focus even while it is visually hidden (a description is
    computed from hidden elements too). <x-forms.input hint="…"> and
    <x-forms.textarea hint="…"> render this themselves; use it directly only
    for a control those components do not cover, and wrap that field and this
    hint in an element with class "group/field" when always is false.
--}}
<{{ $tag }} id="{{ $id }}"
   data-hint
   data-hint-mode="{{ $always ? 'always' : 'focus' }}"
   {{ $attributes->class([
       'mt-1 text-small text-ink-2',
       'hidden group-focus-within/field:block' => ! $always,
   ]) }}>{{ $slot }}</{{ $tag }}>
