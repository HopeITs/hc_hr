{{--
    One step of the guided leave request form (index.blade.php): a numbered
    heading and its body. The step being worked on wears the brand red (the
    current stage); a finished one shows a check on surface; a later one is
    ink on card. Shape and words carry the state, never the colour alone.
    The body is not indented, so a phone keeps its full width for the fields.

    number  the step's place, counted by the caller (a skipped step is not counted)
    title   the step's question
    state   current | done | todo
    help    optional HelpTopics key: a "?" beside the heading (outside it, so the name stays the question)
--}}
@props(['number', 'title', 'state' => 'todo', 'id' => null, 'help' => null])

@php
    $headingId = ($id ?? 'leave-step-'.$number).'-title';
@endphp

<section {{ $attributes->merge(['aria-labelledby' => $headingId, 'data-step-state' => $state]) }}>
    <div class="flex items-center gap-1">
        <h3 id="{{ $headingId }}" class="flex min-h-8 items-center gap-2.5 text-body font-bold text-ink">
            <span @class([
                    'grid size-7 shrink-0 place-items-center rounded-round text-caption font-bold num',
                    'bg-primary text-on-brand' => $state === 'current',
                    'bg-surface text-ink' => $state === 'done',
                    'border-[1.5px] border-line bg-card text-ink-2' => $state === 'todo',
                ]) aria-hidden="true">
                @if($state === 'done')
                    <x-ic name="check" class="size-4" />
                @else
                    {{ $number }}
                @endif
            </span>
            <span class="sr-only">{{ __('leaves.request.step-of', ['number' => $number]) }}:</span>
            <span class="min-w-0">{{ $title }}</span>
            @if($state === 'done')
                <span class="sr-only">({{ __('leaves.request.done') }})</span>
            @endif
        </h3>
        @if($help)
            <x-help.trigger :topic="$help" :label="$title"/>
        @endif
    </div>
    <div class="mt-3">
        {{ $slot }}
    </div>
</section>
