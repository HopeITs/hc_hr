@props([
    'name',
    'model',
    'options',   // [value => label]
    'ariaLabel',
])

{{--
    A 2-way radio toggle styled as pressable segments rather than dots — the
    schedule-mode / pay-mode pattern. Fixed at two columns (both call sites
    are Fixed/Flexible toggles); a class built from count($options) would be
    invisible to Tailwind's static scanner, so this stays a literal utility
    rather than a computed one. The radios stay real inputs (peer-checked
    drives the visual state) so this keeps native keyboard and screen-reader
    semantics instead of reinventing them in Alpine.
--}}
<div {{ $attributes->class('mt-1 grid grid-cols-2 gap-2') }}
     role="radiogroup" aria-label="{{ $ariaLabel }}">
    @foreach ($options as $value => $label)
        <label class="cursor-pointer">
            <input type="radio" class="sr-only peer" name="{{ $name }}"
                   value="{{ $value }}" x-model="{{ $model }}" />
            {{-- Identity choice: line border; chosen = ink border + surface fill (not red: red is not a selection colour). --}}
            <span class="hc-choice flex min-h-11 items-center justify-center px-2 py-2 text-body text-ink">
                <span class="font-semibold truncate">{{ $label }}</span>
            </span>
        </label>
    @endforeach
</div>
