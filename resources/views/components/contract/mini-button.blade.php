@props([
    // Prepends the shared "+" icon — every "Add ..." button in the composer
    // (second shift, pay condition) uses the same glyph; every "Remove ..."
    // button has none.
    'plus' => false,
])

{{--
    The quiet-but-visible control shared by "Add a second shift" / "Remove"
    (schedule), "Add a condition" / "Remove" (pay) and the termination
    section — previously the same class string copy-pasted into each of
    those three files.
--}}
<button
    type="button"
    {{ $attributes->class(
        'inline-flex items-center gap-1.5 rounded-control border border-line bg-card
         px-2.5 py-1 text-caption font-semibold text-ink shadow-e1 transition
         hover:border-ink-2 hover:text-ink active:scale-[0.98]
         focus-visible:outline-2 focus-visible:outline-offset-2'
    ) }}
>
    @if ($plus)
        <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M10 5v10M5 10h10" stroke-linecap="round"/>
        </svg>
    @endif
    {{ $slot }}
</button>
