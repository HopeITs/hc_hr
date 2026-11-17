{{--
    The request's history, one version at a time (D58). The newest version is
    the one people act on, so it is open; earlier versions fold away but keep
    everything that happened on them. One continuous rail per version, filled
    up to how far that version travelled.
--}}
@php
    $states = \App\Services\RecruitmentApprovalTrack::class;
    $trackRounds = array_values($track);
@endphp

<section class="space-y-3" aria-labelledby="track-title">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="track-title" class="text-h4 font-semibold text-neutral-900">{{ __('recruitment.track.title') }}</h2>
        @if (count($trackRounds) > 1)
            <p class="text-small text-secondary-medium tabular-nums">{{ __('recruitment.versions') }}: {{ count($trackRounds) }}</p>
        @endif
    </div>

    @foreach ($trackRounds as $roundIndex => $round)
        @php
            $stops = array_merge($round['stops'], [$round['outcome']]);
            $reached = fn (?array $stop): bool => $stop !== null && in_array($stop['state'], [
                $states::DONE, $states::CURRENT, $states::SKIPPED,
            ], true);
            $latest = $roundIndex === 0;
            $roundLabel = $round['version'] ? __('recruitment.version_no', ['no' => $round['version']]) : __('recruitment.track.draft');
            // Short summary for the folded header: where this version ended up.
            $last = collect($stops)->filter(fn ($s) => $reached($s))->last();
        @endphp

        <details @class(['group rounded-surface border border-neutral-200 bg-white shadow-e1', 'opacity-95' => ! $latest])
                 wire:key="round-{{ $round['version'] ?? 'draft' }}" @if ($latest) open @endif>
            <summary class="flex min-h-11 cursor-pointer list-none flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-surface px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary [&::-webkit-details-marker]:hidden">
                <span class="flex flex-wrap items-center gap-2">
                    <span class="text-small font-semibold text-neutral-900">{{ $roundLabel }}</span>
                    @if ($latest)
                        <span class="rounded-control bg-neutral-900 px-1.5 py-0.5 text-xs font-semibold text-white">{{ __('recruitment.track.latest') }}</span>
                    @elseif ($last)
                        <span class="inline-flex items-center gap-1.5 rounded-control bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-secondary-dark">{{ $last['label'] }}</span>
                    @endif
                </span>
                <span class="flex items-center gap-3 text-caption text-secondary-medium">
                    <span>
                        @if ($round['author']){{ $round['author'] }}@endif
                        @if ($round['created'])
                            <span aria-hidden="true" class="text-neutral-300">·</span>
                            <span class="tabular-nums">{{ $round['created'] }}</span>
                        @endif
                    </span>
                    <flux:icon.chevron-down variant="mini" class="size-5 transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
                </span>
            </summary>

            <div class="border-t border-neutral-100 px-5 pb-5">
                {{-- What changed to make this version, so an approver knows whether their earlier approval still stands (RV-1b). --}}
                @if ($round['edits'])
                    <div class="mt-4 rounded-control bg-neutral-50 p-3">
                        <p class="text-caption font-semibold uppercase tracking-wider rtl:normal-case rtl:tracking-normal text-secondary-medium">{{ __('recruitment.track.changes') }}</p>
                        <ul class="mt-2 space-y-1.5 text-small">
                            @foreach ($round['edits'] as $edit)
                                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5" dir="auto">
                                    <span class="font-semibold text-secondary-dark">{{ __('recruitment.fields.'.$edit['field']) }}</span>
                                    <span class="text-secondary-medium line-through decoration-neutral-400">{{ \Illuminate\Support\Str::limit((string) ($edit['from'] ?? '—'), 40) }}</span>
                                    <flux:icon.arrow-right variant="micro" class="size-3.5 self-center text-secondary-medium rtl:rotate-180" aria-hidden="true" />
                                    <span class="font-semibold text-neutral-900">{{ \Illuminate\Support\Str::limit((string) ($edit['to'] ?? '—'), 40) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Horizontal rail on wide screens, vertical list on phones, so labels never squeeze. --}}
                <ol class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:gap-0" role="list">
                    @foreach ($stops as $index => $stop)
                        @php
                            $isReached = $reached($stop);
                            $nextReached = $reached($stops[$index + 1] ?? null);
                            $isCurrent = $stop['state'] === $states::CURRENT;
                            $dot = match ($stop['state']) {
                                $states::DONE => 'bg-neutral-900 text-white',
                                $states::CURRENT => 'bg-white ring-[3px] ring-inset ring-primary',
                                $states::SKIPPED => 'border-2 border-dashed border-neutral-400 bg-white',
                                default => 'border-2 border-neutral-200 bg-white',
                            };
                        @endphp

                        <li class="relative flex gap-3 sm:flex-1 sm:flex-col sm:items-center sm:gap-0 sm:text-center"
                            wire:key="stop-{{ $round['version'] ?? 'draft' }}-{{ $index }}" @if ($isCurrent) aria-current="step" @endif>
                            <div class="relative flex shrink-0 justify-center sm:h-5 sm:w-full sm:items-center">
                                @unless ($loop->first)
                                    <span aria-hidden="true" class="absolute hidden h-0.5 sm:block sm:start-0 sm:end-1/2 {{ $isReached ? 'bg-neutral-900' : 'bg-neutral-200' }}"></span>
                                @endunless
                                @unless ($loop->last)
                                    <span aria-hidden="true" class="absolute hidden h-0.5 sm:block sm:start-1/2 sm:end-0 {{ $nextReached ? 'bg-neutral-900' : 'bg-neutral-200' }}"></span>
                                    <span aria-hidden="true" class="absolute top-5 -bottom-4 start-1/2 w-0.5 -translate-x-1/2 sm:hidden {{ $nextReached ? 'bg-neutral-900' : 'bg-neutral-200' }}"></span>
                                @endunless
                                <span class="relative z-10 flex size-5 items-center justify-center rounded-full {{ $dot }}">
                                    @if ($stop['state'] === $states::DONE)<flux:icon.check variant="micro" class="size-3" aria-hidden="true" />@endif
                                    <span class="sr-only">{{ __('recruitment.track.states.'.$stop['state']) }}</span>
                                </span>
                            </div>

                            <div class="min-w-0 pb-1 sm:mt-3 sm:w-full sm:px-2">
                                <p @class([
                                    'text-small leading-snug',
                                    'font-semibold text-neutral-900' => $isReached,
                                    'text-secondary-medium' => ! $isReached,
                                ])>{{ $stop['label'] }}</p>
                                @if ($isCurrent && ! $stop['by'])
                                    <p class="mt-0.5 text-caption font-semibold text-primary">{{ __('recruitment.track.states.'.$states::CURRENT) }}</p>
                                @endif

                                @if ($stop['by'] || $stop['at'] || ! empty($stop['role']))
                                    <p class="mt-1 text-caption leading-snug text-secondary-medium">
                                        @if ($stop['by'])<span class="block font-medium text-secondary-dark">{{ $stop['by'] }}</span>@endif
                                        {{-- The role recorded on the decision, never today's (task 10). --}}
                                        @if (! empty($stop['role']))<span class="block">{{ $stop['role'] }}</span>@endif
                                        @if ($stop['at'])<span class="block tabular-nums">{{ $stop['at'] }}</span>@endif
                                    </p>
                                @endif

                                @if (! empty($stop['comment']))
                                    <p class="mt-2 whitespace-pre-wrap break-words rounded-control bg-neutral-50 px-2.5 py-2 text-start text-caption leading-snug text-secondary-dark ring-1 ring-neutral-100" dir="auto">{{ $stop['comment'] }}</p>
                                @endif

                                {{-- Fields this stop opened for editing: the reason the next version exists. --}}
                                @if (! empty($stop['fields']))
                                    <ul class="mt-2 flex flex-wrap gap-1 sm:justify-center">
                                        @foreach ($stop['fields'] as $field)
                                            <li class="rounded-control bg-neutral-100 px-1.5 py-0.5 text-caption font-medium text-secondary-dark">{{ __('recruitment.fields.'.$field) }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </details>
    @endforeach
</section>
