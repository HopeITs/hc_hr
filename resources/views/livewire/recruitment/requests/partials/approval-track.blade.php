{{--
    The request's history, one version at a time.

    A request sent back for changes becomes a new version and takes the road
    again from the start (D58), so its history is several short journeys rather
    than one long one. Each version gets its own rail, and everything that
    happened to that version sits on it: what the requester changed, who decided
    each stop and what they said, and how the version ended.

    The rail carries the progress, not the dots. It is one continuous track of a
    single weight, filled up to how far the version travelled — the earlier
    version drew each segment separately at two different thicknesses, so the
    line visibly stepped at every stop and the eye read the steps instead of the
    journey.
--}}
@php
    $states = \App\Services\RecruitmentApprovalTrack::class;
@endphp

<section class="space-y-4" aria-label="{{ __('recruitment.track.title') }}">
    <h2 class="text-h4 font-semibold">{{ __('recruitment.track.title') }}</h2>

    @foreach ($track as $round)
        @php
            $stops = array_merge($round['stops'], [$round['outcome']]);

            $reached = fn (?array $stop): bool => $stop !== null && in_array($stop['state'], [
                $states::DONE, $states::CURRENT, $states::SKIPPED,
            ], true);
        @endphp

        <article class="rounded-surface border border-neutral-200 bg-white p-5 sm:p-6"
                 wire:key="round-{{ $round['version'] ?? 'draft' }}">

            <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 class="text-small font-semibold text-secondary-dark">
                    @if ($round['version'])
                        {{ __('recruitment.version_no', ['no' => $round['version']]) }}
                    @else
                        {{ __('recruitment.track.draft') }}
                    @endif
                </h3>
                <p class="text-caption text-secondary-light">
                    @if ($round['author']){{ $round['author'] }}@endif
                    @if ($round['created'])
                        <span aria-hidden="true" class="text-neutral-300">·</span>
                        <span class="tabular-nums">{{ $round['created'] }}</span>
                    @endif
                </p>
            </header>

            {{-- What the requester changed to make this version. An approver
                 reading it needs to know whether their own approval still
                 stands, and "edited" alone does not answer that (RV-1b). --}}
            @if ($round['edits'])
                <ul class="mt-4 divide-y divide-neutral-100 rounded-control border border-neutral-100 text-caption">
                    @foreach ($round['edits'] as $edit)
                        <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-3 py-2" dir="auto">
                            <span class="font-semibold text-secondary-dark">{{ __('recruitment.fields.'.$edit['field']) }}</span>
                            <span class="text-secondary-light">
                                <span class="line-through">{{ \Illuminate\Support\Str::limit((string) ($edit['from'] ?? '—'), 40) }}</span>
                                <span aria-hidden="true" class="px-0.5">&rarr;</span>
                                <span class="font-medium text-secondary-dark">{{ \Illuminate\Support\Str::limit((string) ($edit['to'] ?? '—'), 40) }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <ol class="mt-6 flex items-start" role="list">
                @foreach ($stops as $index => $stop)
                    @php
                        $isReached = $reached($stop);
                        // The segment after this dot belongs to the next stop:
                        // the road is only travelled once the next stop is.
                        $nextReached = $reached($stops[$index + 1] ?? null);

                        // Written out rather than interpolated: Tailwind reads
                        // class names as literal text, so a built-up string
                        // would generate nothing at all.
                        $dot = match ($stop['state']) {
                            $states::DONE => 'bg-secondary-dark',
                            $states::CURRENT => 'bg-white ring-2 ring-primary',
                            $states::SKIPPED => 'border border-dashed border-neutral-400 bg-white',
                            default => 'bg-neutral-200',
                        };
                    @endphp

                    <li class="flex flex-1 flex-col items-center text-center"
                        wire:key="stop-{{ $round['version'] ?? 'draft' }}-{{ $index }}">

                        {{-- ---------- The rail ---------- --}}
                        <div class="relative flex h-3 w-full items-center justify-center">
                            @unless ($loop->first)
                                <span aria-hidden="true"
                                      class="absolute start-0 end-1/2 h-0.5 {{ $isReached ? 'bg-secondary-dark' : 'bg-neutral-200' }}"></span>
                            @endunless
                            @unless ($loop->last)
                                <span aria-hidden="true"
                                      class="absolute start-1/2 end-0 h-0.5 {{ $nextReached ? 'bg-secondary-dark' : 'bg-neutral-200' }}"></span>
                            @endunless

                            <span class="relative z-10 size-2.5 shrink-0 rounded-full {{ $dot }}">
                                <span class="sr-only">{{ __('recruitment.track.states.'.$stop['state']) }}</span>
                            </span>
                        </div>

                        {{-- ---------- What happened here ---------- --}}
                        <div class="mt-3 w-full px-1.5">
                            <p class="text-caption font-semibold leading-snug {{ $isReached ? 'text-secondary-dark' : 'text-secondary-light' }}">
                                {{ $stop['label'] }}
                            </p>

                            @if ($stop['by'] || $stop['at'] || ! empty($stop['role']))
                                <p class="mt-1 text-caption leading-snug text-secondary-light">
                                    @if ($stop['by'])<span class="block font-medium text-secondary-medium">{{ $stop['by'] }}</span>@endif

                                    {{-- The role as it was recorded on the
                                         decision, never the one the person holds
                                         today: reading an old approval through
                                         this week's roles would misstate who
                                         made it (task 10). --}}
                                    @if (! empty($stop['role']))<span class="block">{{ $stop['role'] }}</span>@endif

                                    @if ($stop['at'])<span class="block tabular-nums">{{ $stop['at'] }}</span>@endif
                                </p>
                            @endif

                            @if (! empty($stop['comment']))
                                <p class="mt-1.5 whitespace-pre-wrap break-words rounded-control bg-neutral-50 px-2 py-1.5 text-caption leading-snug text-secondary-dark"
                                   dir="auto">{{ $stop['comment'] }}</p>
                            @endif

                            {{-- The fields this stop opened for editing. They
                                 are the reason the next version exists, so they
                                 belong at the stop that asked for them. --}}
                            @if (! empty($stop['fields']))
                                <ul class="mt-1.5 flex flex-wrap justify-center gap-1">
                                    @foreach ($stop['fields'] as $field)
                                        <li class="rounded-control border border-neutral-200 px-1.5 py-0.5 text-caption text-secondary-medium">
                                            {{ __('recruitment.fields.'.$field) }}
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </article>
    @endforeach
</section>
