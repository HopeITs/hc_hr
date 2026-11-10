{{--
    The presence dot (App\Services\WorkPresence, DESIGN.md "Presence dot").
    $status is what WorkPresence returns for one person: ['tone' => online|away|flexible,
    'label' => words]; null draws nothing (no running contract, or a schedule that
    cannot be read). The words are the hover title and what a screen reader says.
    on-avatar: pinned to the corner of a photo (the parent must be `relative`).
--}}
@props(['status' => null, 'onAvatar' => false, 'size' => 'sm'])

@if (is_array($status) && isset($status['tone'], $status['label']))
    <span role="img" aria-label="{{ $status['label'] }}" title="{{ $status['label'] }}"
          data-presence="{{ $status['tone'] }}"
          {{ $attributes->class([
              'presence-dot',
              'presence-dot--'.$status['tone'],
              'presence-dot--on-avatar' => $onAvatar,
              'presence-dot--lg' => $size === 'lg',
          ]) }}></span>
@endif
