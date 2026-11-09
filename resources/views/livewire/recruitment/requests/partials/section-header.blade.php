{{-- Numbered heading of one form section: $number, $id (for aria-labelledby), $title, optional $lead. --}}
<header class="mb-5 flex items-start gap-3 border-b border-line-soft pb-4">
    <span class="num mt-0.5 grid size-7 shrink-0 place-items-center rounded-full border border-line bg-soft text-small font-bold text-ink" aria-hidden="true">{{ $number }}</span>
    <div class="min-w-0">
        <h2 id="{{ $id }}" class="text-h4 font-extrabold text-ink">{{ $title }}</h2>
        @if (filled($lead ?? null))
            <p class="mt-0.5 max-w-[68ch] text-small text-ink-2">{{ $lead }}</p>
        @endif
    </div>
</header>
