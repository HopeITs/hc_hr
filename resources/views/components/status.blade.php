{{--
    The one status chip: icon + words + fill shape, never colour (DESIGN.md).

      <x-status :status="$leave->statusEnum()" />          any App\Support\Status\HasStatusTone
      <x-status tone="wait" icon="clock" :label="__('…')" />

    tone: work (solid ink) | done (surface) | attn (ink outline) | stop (dashed) | wait (card + line)
    icon: an <x-ic> name; defaults to the tone's own icon.
    label: the words; the slot is used when no label is given.
    An explicit tone / icon / label overrides what :status supplies.
--}}
@props(['status' => null, 'tone' => null, 'icon' => null, 'label' => null])

@php
    use App\Support\Status\HasStatusTone;
    use App\Support\Status\StatusTone;

    if ($status instanceof HasStatusTone) {
        $tone ??= $status->tone();
        $icon ??= $status->icon();
        $label ??= $status->label();
    }

    $tone = $tone instanceof StatusTone ? $tone : (StatusTone::tryFrom((string) $tone) ?? StatusTone::Wait);
    $icon ??= $tone->defaultIcon();
@endphp

<span {{ $attributes->class(['hc-chip', 'hc-chip--'.$tone->value]) }} data-tone="{{ $tone->value }}"><x-ic :name="$icon" /><span class="min-w-0 truncate">{{ $label ?? $slot }}</span></span>
